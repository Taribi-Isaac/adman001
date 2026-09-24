<?php

namespace App\Services;

use App\Enums\CommunicationChannel;
use App\Enums\ConversationMode;
use App\Enums\MessageActorType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Jobs\ProcessInboundAiMessage;
use App\Models\Business;
use App\Models\CommunicationIdentity;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\WhatsAppPhone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes verified WhatsApp Cloud API webhook payloads.
 * Records inbound messages and queues AI processing when eligible.
 */
class WhatsAppInboundService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly AuditLogger $auditLogger,
        private readonly InboundAttachmentService $attachments,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handlePayload(array $payload): void
    {
        $entries = $payload['entry'] ?? null;
        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $changes = $entry['changes'] ?? null;
            if (! is_array($changes)) {
                continue;
            }

            foreach ($changes as $change) {
                if (! is_array($change)) {
                    continue;
                }
                $value = $change['value'] ?? null;
                if (! is_array($value)) {
                    continue;
                }

                $this->processStatuses($value['statuses'] ?? null);
                $this->processMessages($value['messages'] ?? null, $value['contacts'] ?? null);
            }
        }
    }

    /**
     * @param  mixed  $statuses
     */
    private function processStatuses(mixed $statuses): void
    {
        if (! is_array($statuses)) {
            return;
        }

        foreach ($statuses as $status) {
            if (! is_array($status)) {
                continue;
            }

            try {
                $this->applyStatusUpdate($status);
            } catch (Throwable $e) {
                report($e);
                Log::warning('whatsapp.status_update_failed', [
                    'provider_id' => $status['id'] ?? null,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function applyStatusUpdate(array $status): void
    {
        $providerId = isset($status['id']) && is_string($status['id']) ? $status['id'] : null;
        $statusValue = isset($status['status']) && is_string($status['status']) ? $status['status'] : null;

        if ($providerId === null || $statusValue === null) {
            return;
        }

        $mapped = match ($statusValue) {
            'sent' => MessageStatus::Sent,
            'delivered' => MessageStatus::Delivered,
            'read' => MessageStatus::Read,
            'failed' => MessageStatus::Failed,
            default => null,
        };

        if ($mapped === null) {
            return;
        }

        DB::transaction(function () use ($providerId, $mapped, $status) {
            /** @var Message|null $message */
            $message = Message::query()
                ->where('channel', CommunicationChannel::WhatsApp->value)
                ->where('external_message_id', $providerId)
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                return;
            }

            if ($mapped === MessageStatus::Failed) {
                if ($message->status->isTerminalSuccess() && $message->status !== MessageStatus::Sent) {
                    // Do not regress delivered/read to failed from a late webhook unless still early.
                }
                $errors = $status['errors'] ?? null;
                $reason = 'WhatsApp reported delivery failure.';
                if (is_array($errors) && isset($errors[0]['title']) && is_string($errors[0]['title'])) {
                    $reason = 'WhatsApp: '.$errors[0]['title'];
                }

                if ($message->status->deliveryRank() < MessageStatus::Delivered->deliveryRank()) {
                    $message->status = MessageStatus::Failed;
                    $message->failure_reason = $reason;
                    $message->failed_at = now();
                    $message->save();
                }

                return;
            }

            // Forward-only status progression.
            if ($mapped->deliveryRank() <= $message->status->deliveryRank()) {
                return;
            }

            $message->status = $mapped;
            if ($mapped === MessageStatus::Sent && $message->sent_at === null) {
                $message->sent_at = now();
            }
            $message->failure_reason = null;
            $message->failed_at = null;
            $message->save();
        });
    }

    /**
     * @param  mixed  $messages
     * @param  mixed  $contacts
     */
    private function processMessages(mixed $messages, mixed $contacts): void
    {
        if (! is_array($messages)) {
            return;
        }

        $contactNames = [];
        if (is_array($contacts)) {
            foreach ($contacts as $contact) {
                if (! is_array($contact)) {
                    continue;
                }
                $waId = isset($contact['wa_id']) ? WhatsAppPhone::normalize((string) $contact['wa_id']) : null;
                $name = data_get($contact, 'profile.name');
                if ($waId !== null && is_string($name) && $name !== '') {
                    $contactNames[$waId] = $name;
                }
            }
        }

        foreach ($messages as $inbound) {
            if (! is_array($inbound)) {
                continue;
            }

            try {
                $this->recordInbound($inbound, $contactNames);
            } catch (Throwable $e) {
                report($e);
                Log::warning('whatsapp.inbound_message_failed', [
                    'provider_id' => $inbound['id'] ?? null,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $inbound
     * @param  array<string, string>  $contactNames
     */
    private function recordInbound(array $inbound, array $contactNames): void
    {
        $providerId = isset($inbound['id']) && is_string($inbound['id']) ? $inbound['id'] : null;
        $fromRaw = isset($inbound['from']) && is_string($inbound['from']) ? $inbound['from'] : null;
        $from = WhatsAppPhone::normalize($fromRaw);

        if ($providerId === null || $from === null) {
            return;
        }

        // Idempotency: unique (channel, external_message_id)
        if (Message::query()
            ->where('channel', CommunicationChannel::WhatsApp->value)
            ->where('external_message_id', $providerId)
            ->exists()) {
            return;
        }

        $type = isset($inbound['type']) && is_string($inbound['type']) ? $inbound['type'] : 'unknown';
        $body = $this->extractBody($inbound, $type);
        $timestamp = isset($inbound['timestamp']) && is_numeric($inbound['timestamp'])
            ? now()->setTimestamp((int) $inbound['timestamp'])
            : now();

        $displayName = $contactNames[$from] ?? null;

        $result = DB::transaction(function () use ($from, $displayName, $providerId, $body, $timestamp, $type, $inbound) {
            // Race-safe insert: catch unique violation.
            if (Message::query()
                ->where('channel', CommunicationChannel::WhatsApp->value)
                ->where('external_message_id', $providerId)
                ->lockForUpdate()
                ->exists()) {
                return null;
            }

            $linkedContact = Contact::query()
                ->where(function ($q) use ($from) {
                    $q->where('whatsapp_id', $from)
                        ->orWhere('whatsapp_id', '+'.$from)
                        ->orWhere('phone', $from)
                        ->orWhere('phone', '+'.$from);
                })
                ->whereNull('archived_at')
                ->orderBy('id')
                ->first();

            // Prefer exact whatsapp_id match after normalization in PHP if multiple.
            if ($linkedContact === null) {
                $candidates = Contact::query()->whereNull('archived_at')->whereNotNull('whatsapp_id')->get();
                foreach ($candidates as $candidate) {
                    if (WhatsAppPhone::normalize($candidate->whatsapp_id) === $from) {
                        $linkedContact = $candidate;
                        break;
                    }
                }
            }
            if ($linkedContact === null) {
                $candidates = Contact::query()->whereNull('archived_at')->whereNotNull('phone')->get();
                foreach ($candidates as $candidate) {
                    if (WhatsAppPhone::fromContact($candidate) === $from) {
                        $linkedContact = $candidate;
                        break;
                    }
                }
            }

            $identity = $this->conversations->findOrCreateIdentity(
                channel: CommunicationChannel::WhatsApp,
                externalId: $from,
                displayName: $displayName ?? $linkedContact?->display_name,
                contact: $linkedContact,
            );

            if ($linkedContact !== null && $identity->contact_id === null) {
                $this->conversations->linkIdentityToContact($identity, $linkedContact);
                $identity->refresh();
            }

            $conversation = Conversation::query()
                ->where('communication_identity_id', $identity->id)
                ->where('mode', '!=', ConversationMode::Closed->value)
                ->latest('id')
                ->first();

            if ($conversation === null) {
                $conversation = $this->conversations->openConversation(
                    identity: $identity,
                    mode: $this->defaultInboundMode(),
                    subject: 'WhatsApp '.$from,
                );
            }

            $messageId = null;
            $inboundPayload = $inbound;
            $inboundType = $type;
            try {
                $hasMedia = in_array($type, ['image', 'audio', 'video', 'document', 'sticker'], true);
                $message = Message::query()->create([
                    'conversation_id' => $conversation->id,
                    'direction' => MessageDirection::Inbound,
                    'channel' => CommunicationChannel::WhatsApp,
                    'body' => $body,
                    'subject' => null,
                    'template_key' => null,
                    'document_id' => null,
                    'status' => MessageStatus::Delivered,
                    'actor_type' => MessageActorType::External,
                    'actor_user_id' => null,
                    'external_message_id' => $providerId,
                    'occurred_at' => $timestamp,
                    'meta' => [
                        'provider_type' => $type,
                        'from' => $from,
                        'raw_type' => $type,
                        'has_media' => $hasMedia,
                        'provider_media_id' => $hasMedia ? data_get($inbound, $type.'.id') : null,
                    ],
                ]);
                $messageId = $message->id;

                if ($hasMedia) {
                    $attachment = $this->attachments->captureFromWhatsAppInbound($message, $inbound, $type);
                    if ($attachment !== null && $attachment->processing_status === 'stored') {
                        $message->body = '[File received: '.($attachment->original_filename ?: $type).' — forwarded to the team for review]';
                        $message->save();
                    } elseif ($attachment !== null) {
                        $message->body = '[File received but could not be stored automatically — staff should follow up]';
                        $message->save();
                    }
                }
            } catch (UniqueConstraintViolationException) {
                return;
            }

            $conversation->last_message_at = $timestamp;
            $conversation->save();

            return [
                'message_id' => $messageId,
                'conversation_mode' => $conversation->mode->value,
                'from' => $from,
                'provider_id' => $providerId,
            ];
        });

        if (is_array($result) && isset($result['message_id']) && is_int($result['message_id'])) {
            if (($result['conversation_mode'] ?? null) === ConversationMode::Ai->value) {
                ProcessInboundAiMessage::dispatch($result['message_id']);
            }
        }

        // Audit sparingly: first-touch unknown inbound only when identity has no contact.
        $identity = CommunicationIdentity::query()
            ->where('channel', CommunicationChannel::WhatsApp->value)
            ->where('external_id', $from)
            ->first();

        if ($identity !== null && $identity->contact_id === null) {
            $this->auditLogger->record(
                event: 'whatsapp.inbound_unknown',
                description: 'Inbound WhatsApp from unknown identity',
                auditable: $identity,
                newValues: [
                    'external_id' => $from,
                    'provider_message_id' => $providerId,
                ],
            );
        }
    }

    private function defaultInboundMode(): ConversationMode
    {
        $business = Business::current();
        if ($business->ai_enabled && $business->ai_customer_responses_enabled && (bool) config('adman.ai.enabled', true)) {
            return ConversationMode::Ai;
        }

        return ConversationMode::Human;
    }

    /**
     * @param  array<string, mixed>  $inbound
     */
    private function extractBody(array $inbound, string $type): string
    {
        return match ($type) {
            'text' => (string) (data_get($inbound, 'text.body') ?: '[Empty text message]'),
            'button' => (string) (data_get($inbound, 'button.text') ?: '[Button reply]'),
            'interactive' => (string) (
                data_get($inbound, 'interactive.button_reply.title')
                ?: data_get($inbound, 'interactive.list_reply.title')
                ?: '[Interactive reply]'
            ),
            'image', 'audio', 'video', 'document', 'sticker' => '[File/media message received]',
            'location' => '[Location shared]',
            'contacts' => '[Contacts shared]',
            'reaction' => '[Reaction]',
            default => '[Unsupported WhatsApp message type: '.$type.']',
        };
    }
}
