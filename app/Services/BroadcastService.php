<?php

namespace App\Services;

use App\Enums\BroadcastAudience;
use App\Enums\BroadcastRecipientStatus;
use App\Enums\BroadcastStatus;
use App\Enums\CommunicationChannel;
use App\Enums\MessageStatus;
use App\Jobs\ProcessBroadcastJob;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use App\Support\Permissions;
use App\Support\WhatsAppBroadcastTemplate;
use App\Support\WhatsAppPhone;
use App\WhatsApp\WhatsAppErrorMapper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Broadcast lifecycle (Task 038): draft → queued → sending → completed / failed, or cancelled.
 *
 * MySQL is the source of truth for "who gets a message": one recipient row per contact
 * (unique), claimed under a row lock before its single Message is created, and that message
 * is guarded again right before the provider call.
 */
class BroadcastService
{
    /** Absolute ceiling; configuration can only lower it. */
    public const HARD_RECIPIENT_LIMIT = 500;

    public function __construct(
        private readonly BroadcastEligibilityService $eligibility,
        private readonly WhatsAppOutboundService $whatsapp,
        private readonly EmailOutboundService $email,
        private readonly AuditLogger $auditLogger,
    ) {}

    public static function recipientLimit(): int
    {
        return max(1, min(self::HARD_RECIPIENT_LIMIT, (int) config('adman.broadcasts.recipient_limit', self::HARD_RECIPIENT_LIMIT)));
    }

    /**
     * @param  array{name: string, channel: string, audience_type: string, selected_contact_ids?: list<int>|null, subject?: string|null, body?: string|null, whatsapp_message?: string|null}  $data
     */
    public function create(array $data, User $actor): Broadcast
    {
        $broadcast = Broadcast::query()->create([
            ...$this->draftAttributes($data),
            'status' => BroadcastStatus::Draft,
            'created_by' => $actor->id,
        ]);

        $this->auditLogger->record(
            event: 'broadcast.created',
            description: 'Broadcast draft created',
            auditable: $broadcast,
            newValues: [
                'name' => $broadcast->name,
                'channel' => $broadcast->channel->value,
                'audience_type' => $broadcast->audience_type->value,
            ],
            actor: $actor,
        );

        return $broadcast;
    }

    /**
     * @param  array{name: string, channel: string, audience_type: string, selected_contact_ids?: list<int>|null, subject?: string|null, body?: string|null, whatsapp_message?: string|null}  $data
     */
    public function update(Broadcast $broadcast, array $data, User $actor): Broadcast
    {
        return DB::transaction(function () use ($broadcast, $data, $actor) {
            $locked = Broadcast::query()->whereKey($broadcast->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw ValidationException::withMessages([
                    'broadcast' => 'Only draft broadcasts can be edited.',
                ]);
            }

            $locked->fill($this->draftAttributes($data))->save();

            $this->auditLogger->record(
                event: 'broadcast.updated',
                description: 'Broadcast draft updated',
                auditable: $locked,
                newValues: [
                    'name' => $locked->name,
                    'channel' => $locked->channel->value,
                    'audience_type' => $locked->audience_type->value,
                ],
                actor: $actor,
            );

            return $locked;
        });
    }

    /**
     * The audience as it stands right now, plus every reason sending would be refused.
     * Read-only: used for the confirmation step and re-run inside start().
     *
     * @return array{
     *     eligible: list<Contact>,
     *     eligible_count: int,
     *     excluded_count: int,
     *     exclusions: array<string, int>,
     *     limit: int,
     *     over_limit: bool,
     *     template: array{name: string, language: string}|null,
     *     blockers: list<string>,
     * }
     */
    public function preview(Broadcast $broadcast): array
    {
        $statuses = $broadcast->audience_type->statuses();
        $eligible = [];
        $exclusions = [];
        $excluded = 0;

        foreach ($this->candidates($broadcast) as $contact) {
            $result = $this->eligibility->check($contact, $broadcast->channel, $statuses);

            if ($result->eligible()) {
                $eligible[] = $contact;

                continue;
            }

            $excluded++;
            foreach ($result->reasons as $reason) {
                $exclusions[$reason->value] = ($exclusions[$reason->value] ?? 0) + 1;
            }
        }

        $limit = self::recipientLimit();
        $count = count($eligible);
        $template = null;
        $blockers = [];

        if (! Business::current()->broadcasts_enabled) {
            $blockers[] = 'Broadcasts are turned off in Business settings.';
        }

        if ($broadcast->channel === CommunicationChannel::WhatsApp) {
            $configured = WhatsAppBroadcastTemplate::configured();
            if ($configured === null) {
                $blockers[] = (string) WhatsAppBroadcastTemplate::problem();
            } else {
                $template = ['name' => $configured->name, 'language' => $configured->language];
                if (($contentProblem = $configured->problemFor($broadcast)) !== null) {
                    $blockers[] = $contentProblem;
                }
            }
        } else {
            if (trim((string) $broadcast->subject) === '' || trim((string) $broadcast->body) === '') {
                $blockers[] = 'An email broadcast needs a subject and a message.';
            }
            if (! $this->emailSenderConfigured()) {
                $blockers[] = 'Sender email is not configured. Set Business email or MAIL_FROM_ADDRESS.';
            }
        }

        if ($count === 0) {
            $blockers[] = 'No contact in this audience is currently eligible to receive this broadcast.';
        }

        if ($count > $limit) {
            $blockers[] = sprintf(
                'The eligible audience (%d) is larger than the broadcast limit of %d recipients. Narrow the audience; nothing will be sent.',
                $count,
                $limit,
            );
        }

        return [
            'eligible' => $eligible,
            'eligible_count' => $count,
            'excluded_count' => $excluded,
            'exclusions' => $exclusions,
            'limit' => $limit,
            'over_limit' => $count > $limit,
            'template' => $template,
            'blockers' => $blockers,
        ];
    }

    /**
     * Explicit owner action: snapshot the eligible audience and queue it for sending.
     * Refuses unless every check passes and the audience still matches what was confirmed.
     */
    public function start(Broadcast $broadcast, User $actor, int $confirmedCount): Broadcast
    {
        if (! $actor->can(Permissions::BROADCASTS_SEND)) {
            throw new AuthorizationException('You are not allowed to send broadcasts.');
        }

        return DB::transaction(function () use ($broadcast, $actor, $confirmedCount) {
            $locked = Broadcast::query()->whereKey($broadcast->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw ValidationException::withMessages([
                    'broadcast' => 'This broadcast has already been sent or cancelled.',
                ]);
            }

            $preview = $this->preview($locked);

            if ($preview['blockers'] !== []) {
                throw ValidationException::withMessages(['broadcast' => $preview['blockers'][0]]);
            }

            if ($preview['eligible_count'] !== $confirmedCount) {
                throw ValidationException::withMessages([
                    'broadcast' => sprintf(
                        'The eligible audience changed from %d to %d since you reviewed it. Review the broadcast again before sending.',
                        $confirmedCount,
                        $preview['eligible_count'],
                    ),
                ]);
            }

            foreach ($preview['eligible'] as $contact) {
                BroadcastRecipient::query()->create([
                    'broadcast_id' => $locked->id,
                    'contact_id' => $contact->id,
                    'channel' => $locked->channel,
                    'address' => $locked->channel === CommunicationChannel::WhatsApp
                        ? WhatsAppPhone::fromContact($contact)
                        : strtolower(trim((string) $contact->email)),
                    'status' => BroadcastRecipientStatus::Pending,
                ]);
            }

            $locked->forceFill([
                'status' => BroadcastStatus::Queued,
                'recipient_count' => $preview['eligible_count'],
                'recipient_limit' => $preview['limit'],
                'exclusion_summary' => $preview['exclusions'],
                'whatsapp_template_name' => $preview['template']['name'] ?? null,
                'whatsapp_template_language' => $preview['template']['language'] ?? null,
                'sent_by' => $actor->id,
                'send_requested_at' => now(),
            ])->save();

            $this->auditLogger->record(
                event: 'broadcast.send_requested',
                description: 'Broadcast sending requested',
                auditable: $locked,
                newValues: [
                    'channel' => $locked->channel->value,
                    'audience_type' => $locked->audience_type->value,
                    'recipient_count' => $preview['eligible_count'],
                    'excluded_count' => $preview['excluded_count'],
                    'recipient_limit' => $preview['limit'],
                    'whatsapp_template_name' => $locked->whatsapp_template_name,
                ],
                actor: $actor,
            );

            ProcessBroadcastJob::dispatch($locked->id)->afterCommit();

            return $locked;
        });
    }

    /**
     * Queue the next batch of pending recipients. Returns true when more remain.
     */
    public function processNextBatch(int $broadcastId): bool
    {
        $broadcast = Broadcast::query()->find($broadcastId);
        if ($broadcast === null) {
            return false;
        }

        if ($broadcast->status === BroadcastStatus::Queued) {
            $claimed = Broadcast::query()
                ->whereKey($broadcast->id)
                ->where('status', BroadcastStatus::Queued->value)
                ->update(['status' => BroadcastStatus::Sending->value, 'started_at' => now(), 'updated_at' => now()]);

            if ($claimed === 1) {
                $broadcast->refresh();
                $this->auditLogger->record(
                    event: 'broadcast.started',
                    description: 'Broadcast sending started',
                    auditable: $broadcast,
                    newValues: ['recipient_count' => $broadcast->recipient_count],
                );
            }

            $broadcast->refresh();
        }

        if ($broadcast->status !== BroadcastStatus::Sending) {
            return false;
        }

        $actor = $broadcast->sent_by !== null ? User::query()->find($broadcast->sent_by) : null;
        if ($actor === null) {
            $this->stop($broadcast->id, 'The user who started this broadcast no longer exists.');

            return false;
        }

        $template = null;
        if ($broadcast->channel === CommunicationChannel::WhatsApp) {
            $template = WhatsAppBroadcastTemplate::configured();
            if ($template === null || $template->name !== $broadcast->whatsapp_template_name) {
                $this->stop($broadcast->id, $template === null
                    ? (string) WhatsAppBroadcastTemplate::problem()
                    : 'The WhatsApp broadcast template configuration changed after this broadcast started.');

                return false;
            }

            if (($contentProblem = $template->problemFor($broadcast)) !== null) {
                $this->stop($broadcast->id, $contentProblem);

                return false;
            }
        }

        $recipientIds = BroadcastRecipient::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('status', BroadcastRecipientStatus::Pending->value)
            ->orderBy('id')
            ->limit(max(1, (int) config('adman.broadcasts.batch_size', 20)))
            ->pluck('id');

        foreach ($recipientIds as $recipientId) {
            $this->queueRecipient((int) $recipientId, $broadcast, $actor, $template);
        }

        $more = BroadcastRecipient::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('status', BroadcastRecipientStatus::Pending->value)
            ->exists();

        if (! $more) {
            $this->finalizeIfDone($broadcast->id);
        }

        return $more && Broadcast::query()->whereKey($broadcast->id)->value('status') === BroadcastStatus::Sending;
    }

    public function cancel(Broadcast $broadcast, User $actor): Broadcast
    {
        return DB::transaction(function () use ($broadcast, $actor) {
            $locked = Broadcast::query()->whereKey($broadcast->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canBeCancelled()) {
                throw ValidationException::withMessages([
                    'broadcast' => 'This broadcast has already finished and can no longer be cancelled.',
                ]);
            }

            $previous = $locked->status;
            $cancelledRecipients = $this->cancelPendingRecipients($locked->id, 'Broadcast cancelled before this message was queued.');

            $locked->forceFill([
                'status' => BroadcastStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ])->save();

            $this->auditLogger->record(
                event: 'broadcast.cancelled',
                description: 'Broadcast cancelled',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: [
                    'status' => BroadcastStatus::Cancelled->value,
                    'recipients_cancelled' => $cancelledRecipients,
                ],
                actor: $actor,
            );

            return $locked;
        });
    }

    /**
     * Stop a broadcast that cannot continue (account-level provider error, configuration
     * change, repeated processing failure). Already-sent messages stay recorded.
     */
    public function stop(int $broadcastId, string $reason): void
    {
        $stopped = DB::transaction(function () use ($broadcastId, $reason) {
            $locked = Broadcast::query()->whereKey($broadcastId)->lockForUpdate()->first();

            if ($locked === null || ! $locked->status->isActive()) {
                return null;
            }

            $cancelled = $this->cancelPendingRecipients($locked->id, 'Broadcast stopped: '.$reason);

            $locked->forceFill([
                'status' => BroadcastStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => $reason,
            ])->save();

            return [$locked, $cancelled];
        });

        if ($stopped === null) {
            return;
        }

        [$broadcast, $cancelled] = $stopped;

        $this->auditLogger->record(
            event: 'broadcast.failed',
            description: 'Broadcast stopped',
            auditable: $broadcast,
            newValues: [
                'status' => BroadcastStatus::Failed->value,
                'recipients_cancelled' => $cancelled,
                ...$this->counts($broadcast->id),
            ],
            meta: ['reason' => $reason],
        );
    }

    /**
     * Mirror the delivery status of a broadcast message onto its recipient row.
     * Called from the Message observer for every status change on a broadcast message.
     */
    public function syncRecipientFromMessage(Message $message): void
    {
        $recipientId = (int) ($message->meta['broadcast_recipient_id'] ?? 0);
        if ($recipientId === 0) {
            return;
        }

        $transition = DB::transaction(function () use ($message, $recipientId) {
            $recipient = BroadcastRecipient::query()->whereKey($recipientId)->lockForUpdate()->first();

            if ($recipient === null || (int) $recipient->message_id !== (int) $message->id) {
                return null;
            }

            if (! in_array($recipient->status, BroadcastRecipientStatus::syncable(), true)) {
                return ['recipient' => $recipient, 'event' => null];
            }

            $wasSent = in_array($recipient->status, [BroadcastRecipientStatus::Sent, BroadcastRecipientStatus::Delivered], true);
            $event = null;

            switch ($message->status) {
                case MessageStatus::Sent:
                case MessageStatus::Delivered:
                case MessageStatus::Read:
                    $delivered = $message->status !== MessageStatus::Sent;
                    $recipient->forceFill([
                        'status' => $delivered || $recipient->status === BroadcastRecipientStatus::Delivered
                            ? BroadcastRecipientStatus::Delivered
                            : BroadcastRecipientStatus::Sent,
                        'provider_message_id' => $message->external_message_id ?? $recipient->provider_message_id,
                        'sent_at' => $recipient->sent_at ?? $message->sent_at ?? now(),
                        'delivered_at' => $delivered ? ($recipient->delivered_at ?? now()) : $recipient->delivered_at,
                        'failure_reason' => null,
                    ])->save();
                    $event = $wasSent ? null : 'sent';
                    break;

                case MessageStatus::Failed:
                    if ($recipient->status !== BroadcastRecipientStatus::Failed) {
                        $recipient->forceFill([
                            'status' => BroadcastRecipientStatus::Failed,
                            'failure_reason' => $message->failure_reason,
                            'failed_at' => $message->failed_at ?? now(),
                            'provider_message_id' => $message->external_message_id ?? $recipient->provider_message_id,
                        ])->save();
                        $event = 'failed';
                    }
                    break;

                default:
                    break;
            }

            return ['recipient' => $recipient, 'event' => $event];
        });

        if ($transition === null) {
            return;
        }

        /** @var BroadcastRecipient $recipient */
        $recipient = $transition['recipient'];

        if ($transition['event'] === 'sent') {
            $this->auditLogger->record(
                event: 'broadcast.recipient_sent',
                description: 'Broadcast message accepted by the provider',
                auditable: $recipient->broadcast,
                newValues: [
                    'recipient_id' => $recipient->id,
                    'contact_id' => $recipient->contact_id,
                    'message_id' => $message->id,
                    'provider_message_id' => $recipient->provider_message_id,
                ],
            );
        }

        if ($transition['event'] === 'failed') {
            $this->auditLogger->record(
                event: 'broadcast.recipient_failed',
                description: 'Broadcast message failed',
                auditable: $recipient->broadcast,
                newValues: [
                    'recipient_id' => $recipient->id,
                    'contact_id' => $recipient->contact_id,
                    'message_id' => $message->id,
                ],
                meta: ['failure_reason' => $recipient->failure_reason],
            );

            if ($recipient->channel === CommunicationChannel::WhatsApp
                && WhatsAppErrorMapper::isAccountLevelReason($recipient->failure_reason)) {
                $this->stop($recipient->broadcast_id, 'WhatsApp reported an account-level problem: '.$recipient->failure_reason);
            }
        }

        $this->finalizeIfDone($recipient->broadcast_id);
    }

    /**
     * Complete a sending broadcast once no recipient is pending or awaiting the provider.
     */
    public function finalizeIfDone(int $broadcastId): void
    {
        $finished = DB::transaction(function () use ($broadcastId) {
            $locked = Broadcast::query()->whereKey($broadcastId)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== BroadcastStatus::Sending) {
                return null;
            }

            $open = BroadcastRecipient::query()
                ->where('broadcast_id', $locked->id)
                ->whereIn('status', array_map(fn ($s) => $s->value, BroadcastRecipientStatus::open()))
                ->exists();

            if ($open) {
                return null;
            }

            $counts = $this->counts($locked->id);
            $allFailed = $counts['sent'] === 0 && $counts['failed'] > 0;

            $locked->forceFill($allFailed
                ? ['status' => BroadcastStatus::Failed, 'failed_at' => now(), 'failure_reason' => 'No broadcast message could be sent.']
                : ['status' => BroadcastStatus::Completed, 'completed_at' => now()])->save();

            return [$locked, $counts];
        });

        if ($finished === null) {
            return;
        }

        [$broadcast, $counts] = $finished;

        $this->auditLogger->record(
            event: $broadcast->status === BroadcastStatus::Failed ? 'broadcast.failed' : 'broadcast.completed',
            description: $broadcast->status === BroadcastStatus::Failed ? 'Broadcast finished with no successful sends' : 'Broadcast completed',
            auditable: $broadcast,
            newValues: ['status' => $broadcast->status->value, ...$counts],
        );
    }

    /**
     * @return array{total: int, pending: int, queued: int, sent: int, delivered: int, failed: int, skipped: int, cancelled: int}
     */
    public function counts(int $broadcastId): array
    {
        $byStatus = BroadcastRecipient::query()
            ->where('broadcast_id', $broadcastId)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $get = fn (BroadcastRecipientStatus $status) => (int) ($byStatus[$status->value] ?? 0);
        $delivered = $get(BroadcastRecipientStatus::Delivered);

        return [
            'total' => (int) $byStatus->sum(),
            'pending' => $get(BroadcastRecipientStatus::Pending),
            'queued' => $get(BroadcastRecipientStatus::Queued),
            // "sent" counts every message the provider accepted, including those since delivered.
            'sent' => $get(BroadcastRecipientStatus::Sent) + $delivered,
            'delivered' => $delivered,
            'failed' => $get(BroadcastRecipientStatus::Failed),
            'skipped' => $get(BroadcastRecipientStatus::Skipped),
            'cancelled' => $get(BroadcastRecipientStatus::Cancelled),
        ];
    }

    /**
     * Claim one pending recipient and create its single outbound message.
     */
    private function queueRecipient(int $recipientId, Broadcast $broadcast, User $actor, ?WhatsAppBroadcastTemplate $template): void
    {
        DB::transaction(function () use ($recipientId, $broadcast, $actor, $template) {
            $recipient = BroadcastRecipient::query()->whereKey($recipientId)->lockForUpdate()->first();

            if ($recipient === null || $recipient->status !== BroadcastRecipientStatus::Pending) {
                return;
            }

            if (Broadcast::query()->whereKey($broadcast->id)->value('status') !== BroadcastStatus::Sending) {
                return;
            }

            $contact = Contact::query()->find($recipient->contact_id);
            $result = $contact === null
                ? null
                : $this->eligibility->check($contact, $broadcast->channel, $broadcast->audience_type->statuses());

            if ($contact === null || $result === null || ! $result->eligible()) {
                $recipient->forceFill([
                    'status' => BroadcastRecipientStatus::Skipped,
                    'failure_reason' => 'No longer eligible: '.($result?->firstReason()?->label() ?? 'contact removed').'.',
                ])->save();

                return;
            }

            try {
                $message = $broadcast->channel === CommunicationChannel::WhatsApp && $template !== null
                    ? $this->whatsapp->queueBroadcastTemplate($broadcast, $recipient, $contact, $template, $actor)
                    : $this->email->queueBroadcastEmail($broadcast, $recipient, $contact, $actor);
            } catch (ValidationException $e) {
                $reason = collect($e->errors())->flatten()->first();
                $recipient->forceFill([
                    'status' => BroadcastRecipientStatus::Failed,
                    'failure_reason' => is_string($reason) ? $reason : 'Could not queue this broadcast message.',
                    'failed_at' => now(),
                ])->save();

                $this->auditLogger->record(
                    event: 'broadcast.recipient_failed',
                    description: 'Broadcast message could not be queued',
                    auditable: $broadcast,
                    newValues: ['recipient_id' => $recipient->id, 'contact_id' => $recipient->contact_id],
                    meta: ['failure_reason' => $recipient->failure_reason],
                );

                return;
            }

            $recipient->forceFill([
                'status' => BroadcastRecipientStatus::Queued,
                'message_id' => $message->id,
                'communication_identity_id' => $message->conversation()->value('communication_identity_id'),
                'queued_at' => now(),
            ])->save();

            $this->auditLogger->record(
                event: 'broadcast.recipient_queued',
                description: 'Broadcast message queued',
                auditable: $broadcast,
                newValues: [
                    'recipient_id' => $recipient->id,
                    'contact_id' => $recipient->contact_id,
                    'message_id' => $message->id,
                ],
            );
        });
    }

    private function cancelPendingRecipients(int $broadcastId, string $reason): int
    {
        return BroadcastRecipient::query()
            ->where('broadcast_id', $broadcastId)
            ->where('status', BroadcastRecipientStatus::Pending->value)
            ->update([
                'status' => BroadcastRecipientStatus::Cancelled->value,
                'failure_reason' => mb_substr($reason, 0, 1000),
                'updated_at' => now(),
            ]);
    }

    /**
     * Every contact the audience could include; eligibility decides who actually receives it.
     *
     * @return iterable<Contact>
     */
    private function candidates(Broadcast $broadcast): iterable
    {
        $query = Contact::query();

        if ($broadcast->audience_type === BroadcastAudience::Selected) {
            $ids = array_values(array_unique(array_map('intval', $broadcast->selected_contact_ids ?? [])));
            if ($ids === []) {
                return [];
            }
            $query->whereIn('id', $ids);
        } else {
            $query->whereIn('status', array_map(fn ($status) => $status->value, $broadcast->audience_type->statuses()));
        }

        return $query->lazyById(200);
    }

    private function emailSenderConfigured(): bool
    {
        foreach ([Business::current()->email, config('mail.from.address')] as $address) {
            if (filled($address) && filter_var((string) $address, FILTER_VALIDATE_EMAIL) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function draftAttributes(array $data): array
    {
        $channel = CommunicationChannel::from((string) $data['channel']);
        $audience = BroadcastAudience::from((string) $data['audience_type']);

        return [
            'name' => trim((string) $data['name']),
            'channel' => $channel,
            'audience_type' => $audience,
            'selected_contact_ids' => $audience === BroadcastAudience::Selected
                ? array_values(array_unique(array_map('intval', (array) ($data['selected_contact_ids'] ?? []))))
                : null,
            'subject' => $channel === CommunicationChannel::Email ? trim((string) ($data['subject'] ?? '')) : null,
            'body' => $channel === CommunicationChannel::Email ? trim((string) ($data['body'] ?? '')) : null,
            'whatsapp_message' => $channel === CommunicationChannel::WhatsApp
                ? (trim(str_replace(["\r\n", "\r"], "\n", (string) ($data['whatsapp_message'] ?? ''))) ?: null)
                : null,
        ];
    }
}
