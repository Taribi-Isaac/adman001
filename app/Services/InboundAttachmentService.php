<?php

namespace App\Services;

use App\Contracts\WhatsAppMediaClient;
use App\Enums\AttachmentReviewStatus;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Notifications\InboundAttachmentReceived;
use App\Support\Permissions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores inbound WhatsApp media privately and notifies authorized staff for review.
 */
class InboundAttachmentService
{
    /** @var list<string> */
    private const ALLOWED_KINDS = ['image', 'audio', 'video', 'document', 'sticker'];

    public function __construct(
        private readonly WhatsAppMediaClient $media,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $inbound
     */
    public function captureFromWhatsAppInbound(Message $message, array $inbound, string $type): ?MessageAttachment
    {
        if (! in_array($type, self::ALLOWED_KINDS, true)) {
            return null;
        }

        $mediaId = (string) (data_get($inbound, $type.'.id') ?: '');
        if ($mediaId === '') {
            return null;
        }

        $filename = (string) (data_get($inbound, $type.'.filename')
            ?: data_get($inbound, $type.'.caption')
            ?: ($type.'-'.$mediaId));
        $filename = $this->safeFilename($filename, $type);

        $download = $this->media->download($mediaId);
        $message->loadMissing(['conversation', 'conversation.contact', 'conversation.identity']);

        if (! $download->success || $download->binary === null) {
            $failed = MessageAttachment::query()->create([
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'contact_id' => $message->conversation?->contact_id
                    ?? $message->conversation?->identity?->contact_id,
                'provider_media_id' => $mediaId,
                'original_filename' => $filename,
                'mime_type' => null,
                'byte_size' => null,
                'disk' => 'local',
                'path' => 'inbound/failed/'.$message->id.'/'.Str::uuid().'.bin',
                'media_kind' => $type,
                'processing_status' => 'failed',
                'review_status' => AttachmentReviewStatus::PendingReview,
                'failure_reason' => $download->failureReason ?? 'Media download failed.',
                'meta' => ['provider_type' => $type],
            ]);

            $this->auditLogger->record(
                event: 'attachment.inbound_failed',
                description: 'Inbound WhatsApp media could not be stored',
                auditable: $failed,
                newValues: ['message_id' => $message->id, 'media_kind' => $type],
            );

            return $failed;
        }

        $mime = $download->mimeType ?: 'application/octet-stream';
        $byteSize = strlen($download->binary);
        $maxBytes = (int) config('adman.whatsapp.inbound_media_max_bytes', 15 * 1024 * 1024);

        if ($maxBytes > 0 && $byteSize > $maxBytes) {
            return MessageAttachment::query()->create([
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'contact_id' => $message->conversation?->contact_id
                    ?? $message->conversation?->identity?->contact_id,
                'provider_media_id' => $mediaId,
                'original_filename' => $filename,
                'mime_type' => $mime,
                'byte_size' => $byteSize,
                'disk' => 'local',
                'path' => 'inbound/rejected/'.$message->id.'/'.Str::uuid().'.bin',
                'media_kind' => $type,
                'processing_status' => 'failed',
                'review_status' => AttachmentReviewStatus::PendingReview,
                'failure_reason' => 'Inbound media exceeds the maximum allowed size.',
                'meta' => ['provider_type' => $type, 'max_bytes' => $maxBytes],
            ]);
        }

        if (! $this->mimeAllowed($mime, $type)) {
            return MessageAttachment::query()->create([
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'contact_id' => $message->conversation?->contact_id
                    ?? $message->conversation?->identity?->contact_id,
                'provider_media_id' => $mediaId,
                'original_filename' => $filename,
                'mime_type' => $mime,
                'byte_size' => $byteSize,
                'disk' => 'local',
                'path' => 'inbound/rejected/'.$message->id.'/'.Str::uuid().'.bin',
                'media_kind' => $type,
                'processing_status' => 'failed',
                'review_status' => AttachmentReviewStatus::PendingReview,
                'failure_reason' => 'Inbound media type is not allowed.',
                'meta' => ['provider_type' => $type],
            ]);
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: $this->extensionForMime($mime);
        $path = 'inbound/'.$message->conversation_id.'/'.$message->id.'/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($path, $download->binary);

        $attachment = MessageAttachment::query()->create([
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'contact_id' => $message->conversation?->contact_id
                ?? $message->conversation?->identity?->contact_id,
            'provider_media_id' => $mediaId,
            'original_filename' => $filename,
            'mime_type' => $mime,
            'byte_size' => $byteSize,
            'disk' => 'local',
            'path' => $path,
            'media_kind' => $type,
            'processing_status' => 'stored',
            'review_status' => AttachmentReviewStatus::PendingReview,
            'meta' => [
                'provider_type' => $type,
                'caption' => data_get($inbound, $type.'.caption'),
            ],
        ]);

        $this->auditLogger->record(
            event: 'attachment.inbound_stored',
            description: 'Inbound WhatsApp media stored for staff review',
            auditable: $attachment,
            newValues: [
                'message_id' => $message->id,
                'media_kind' => $type,
                'mime_type' => $mime,
            ],
        );

        $this->notifyReviewers($attachment);

        return $attachment;
    }

    private function notifyReviewers(MessageAttachment $attachment): void
    {
        try {
            $users = User::query()
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $user) => $user->can(Permissions::ATTACHMENTS_REVIEW)
                    || $user->can(Permissions::CONVERSATIONS_VIEW));

            if ($users->isEmpty()) {
                return;
            }

            Notification::send($users->all(), new InboundAttachmentReceived($attachment));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function safeFilename(string $name, string $type): string
    {
        $name = basename(str_replace(["\0", '..'], '', $name));
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = $type.'-attachment';
        }

        return mb_substr($name, 0, 180);
    }

    private function mimeAllowed(string $mime, string $type): bool
    {
        $mime = strtolower($mime);

        return match ($type) {
            'image', 'sticker' => str_starts_with($mime, 'image/'),
            'audio' => str_starts_with($mime, 'audio/'),
            'video' => str_starts_with($mime, 'video/'),
            'document' => in_array($mime, [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/plain',
                'image/jpeg',
                'image/png',
            ], true) || str_starts_with($mime, 'application/'),
            default => false,
        };
    }

    private function extensionForMime(string $mime): string
    {
        return match (strtolower($mime)) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'audio/ogg', 'audio/opus' => 'ogg',
            'video/mp4' => 'mp4',
            default => 'bin',
        };
    }
}
