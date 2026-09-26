<?php

namespace App\Http\Controllers\Attachments;

use App\Enums\AttachmentReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attachments\ReviewMessageAttachmentRequest;
use App\Models\MessageAttachment;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageAttachmentController extends Controller
{
    public function download(MessageAttachment $attachment): StreamedResponse|BinaryFileResponse
    {
        $this->authorize(Permissions::ATTACHMENTS_VIEW);

        abort_unless(
            $attachment->processing_status === 'stored' && $attachment->existsOnDisk(),
            404,
            'Attachment file is not available.',
        );

        $filename = $attachment->original_filename ?: ('attachment-'.$attachment->id);
        $mime = $attachment->mime_type ?: 'application/octet-stream';

        return Storage::disk($attachment->disk)->download($attachment->path, $filename, [
            'Content-Type' => $mime,
        ]);
    }

    public function review(
        ReviewMessageAttachmentRequest $request,
        MessageAttachment $attachment,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $this->authorize(Permissions::ATTACHMENTS_REVIEW);

        $data = $request->validated();
        $old = $attachment->only(['review_status', 'reviewer_notes', 'reviewed_by', 'reviewed_at']);

        $attachment->review_status = AttachmentReviewStatus::from($data['review_status']);
        $attachment->reviewer_notes = $data['reviewer_notes'] ?? null;
        $attachment->reviewed_by = auth()->id();
        $attachment->reviewed_at = now();
        $attachment->save();

        $auditLogger->record(
            event: 'attachment.reviewed',
            description: 'Inbound attachment review status updated',
            auditable: $attachment,
            oldValues: $old,
            newValues: $attachment->only(['review_status', 'reviewer_notes', 'reviewed_by', 'reviewed_at']),
            actor: auth()->user(),
        );

        return back()->with('success', 'Attachment review saved.');
    }
}
