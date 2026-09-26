<?php

namespace App\Services;

use App\Enums\CommunicationChannel;
use App\Enums\InvoiceLifecycleStatus;
use App\Enums\ReminderChannelPreference;
use App\Enums\ReminderOccurrenceStatus;
use App\Jobs\ProcessInvoiceReminderOccurrence;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\ReminderOccurrence;
use App\Models\ReminderRule;
use App\Models\User;
use App\Support\Money;
use App\Support\WhatsAppPhone;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Specialized invoice reminder automation — not a generic workflow engine.
 *
 * RecurringBillingService must not call this. Reminders treat all issued invoices equally.
 */
class ReminderService
{
    public const OFFSET_MIN = -90;

    public const OFFSET_MAX = 90;

    public function __construct(
        private readonly EmailOutboundService $emails,
        private readonly WhatsAppOutboundService $whatsapp,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Ensure default rules exist for the current business.
     *
     * @return Collection<int, ReminderRule>
     */
    public function ensureDefaultRules(?Business $business = null): Collection
    {
        $business ??= Business::current();

        $defaults = [-7, -2, 1];
        foreach ($defaults as $index => $offset) {
            ReminderRule::query()->firstOrCreate(
                [
                    'business_id' => $business->id,
                    'offset_days' => $offset,
                ],
                [
                    'is_enabled' => true,
                    'sort_order' => $index + 1,
                ],
            );
        }

        return ReminderRule::query()
            ->where('business_id', $business->id)
            ->orderBy('sort_order')
            ->orderBy('offset_days')
            ->get();
    }

    /**
     * @param  list<array{id?: int, offset_days: int, is_enabled: bool}>  $rules
     * @return Collection<int, ReminderRule>
     */
    public function syncRules(Business $business, bool $enabled, array $rules, ?User $actor = null): Collection
    {
        $oldEnabled = $business->invoice_reminders_enabled;
        $business->invoice_reminders_enabled = $enabled;
        $business->save();

        if ($oldEnabled !== $enabled) {
            $this->auditLogger->record(
                event: $enabled ? 'reminders.enabled' : 'reminders.disabled',
                description: $enabled ? 'Invoice reminders enabled' : 'Invoice reminders disabled',
                auditable: $business,
                actor: $actor,
            );
        }

        $this->validateRulePayload($rules);

        $keptIds = [];

        foreach (array_values($rules) as $index => $row) {
            $offset = (int) $row['offset_days'];
            $isEnabled = (bool) $row['is_enabled'];
            $id = isset($row['id']) ? (int) $row['id'] : null;

            if ($id) {
                /** @var ReminderRule|null $rule */
                $rule = ReminderRule::query()
                    ->where('business_id', $business->id)
                    ->whereKey($id)
                    ->first();

                if ($rule === null) {
                    throw ValidationException::withMessages([
                        'rules' => 'Unknown reminder rule.',
                    ]);
                }

                $old = $rule->only(['offset_days', 'is_enabled', 'sort_order']);
                $rule->offset_days = $offset;
                $rule->is_enabled = $isEnabled;
                $rule->sort_order = $index + 1;
                $rule->save();
                $keptIds[] = $rule->id;

                if ($old['offset_days'] !== $rule->offset_days || (bool) $old['is_enabled'] !== $rule->is_enabled) {
                    $this->auditLogger->record(
                        event: 'reminder_rule.updated',
                        description: 'Reminder rule updated',
                        auditable: $rule,
                        oldValues: $old,
                        newValues: $rule->only(['offset_days', 'is_enabled', 'sort_order']),
                        actor: $actor,
                    );
                }
            } else {
                $rule = ReminderRule::query()->create([
                    'business_id' => $business->id,
                    'offset_days' => $offset,
                    'is_enabled' => $isEnabled,
                    'sort_order' => $index + 1,
                ]);
                $keptIds[] = $rule->id;

                $this->auditLogger->record(
                    event: 'reminder_rule.created',
                    description: 'Reminder rule created',
                    auditable: $rule,
                    newValues: $rule->only(['offset_days', 'is_enabled', 'sort_order']),
                    actor: $actor,
                );
            }
        }

        // Disable rules removed from the form (retain history / FK).
        ReminderRule::query()
            ->where('business_id', $business->id)
            ->whereNotIn('id', $keptIds)
            ->where('is_enabled', true)
            ->get()
            ->each(function (ReminderRule $rule) use ($actor) {
                $rule->is_enabled = false;
                $rule->save();
                $this->auditLogger->record(
                    event: 'reminder_rule.disabled',
                    description: 'Reminder rule disabled (removed from active set)',
                    auditable: $rule,
                    actor: $actor,
                );
            });

        return $this->ensureDefaultRules($business)->filter(fn (ReminderRule $r) => in_array($r->id, $keptIds, true) || $r->is_enabled)->values();
    }

    /**
     * Find due reminder slots for business today and claim occurrences, then optionally dispatch jobs.
     *
     * @return Collection<int, ReminderOccurrence>
     */
    public function processDue(?CarbonImmutable $businessToday = null, bool $dispatchJobs = true): Collection
    {
        $business = Business::current();
        if (! $business->invoice_reminders_enabled) {
            return collect();
        }

        $today = $businessToday ?? $this->businessToday($business);
        $rules = ReminderRule::query()
            ->where('business_id', $business->id)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->get();

        if ($rules->isEmpty()) {
            return collect();
        }

        $claimed = collect();

        foreach ($rules as $rule) {
            // due_date + offset_days = today  ⇒  due_date = today - offset_days
            $targetDueDate = $today->subDays($rule->offset_days)->toDateString();

            $invoices = Invoice::query()
                ->with('contact')
                ->where('lifecycle_status', InvoiceLifecycleStatus::Issued->value)
                ->whereDate('due_date', $targetDueDate)
                ->orderBy('id')
                ->get();

            foreach ($invoices as $invoice) {
                foreach ($this->channelsFor($invoice) as $channel) {
                    $occurrence = $this->claimOccurrence($invoice, $rule, $channel, $today);
                    if ($occurrence !== null) {
                        $claimed->push($occurrence);
                        if ($dispatchJobs) {
                            ProcessInvoiceReminderOccurrence::dispatch($occurrence->id);
                        }
                    }
                }
            }
        }

        return $claimed;
    }

    /**
     * Process a single claimed occurrence (eligibility recheck + channel send).
     */
    public function processOccurrence(ReminderOccurrence $occurrence): ReminderOccurrence
    {
        return DB::transaction(function () use ($occurrence) {
            /** @var ReminderOccurrence $locked */
            $locked = ReminderOccurrence::query()
                ->whereKey($occurrence->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, [ReminderOccurrenceStatus::Pending], true)) {
                return $locked;
            }

            $invoice = Invoice::query()
                ->whereKey($locked->invoice_id)
                ->lockForUpdate()
                ->with(['contact', 'documents'])
                ->firstOrFail();

            $rule = ReminderRule::query()->whereKey($locked->reminder_rule_id)->firstOrFail();
            $business = Business::current();

            if (! $business->invoice_reminders_enabled || ! $rule->is_enabled) {
                return $this->markSkipped($locked, 'Reminders disabled');
            }

            $ineligible = $this->ineligibilityReason($invoice);
            if ($ineligible !== null) {
                return $this->markSkipped($locked, $ineligible);
            }

            $channelBlock = $this->channelIneligibility($invoice, $locked->channel);
            if ($channelBlock !== null) {
                return $this->markNotDeliverable($locked, $channelBlock);
            }

            $actor = $this->systemActor();

            try {
                $message = match ($locked->channel) {
                    CommunicationChannel::Email => $this->emails->queueInvoiceReminderEmail($invoice, $actor),
                    CommunicationChannel::WhatsApp => $this->whatsapp->queueInvoiceReminderWhatsApp($invoice, $actor),
                    default => throw ValidationException::withMessages([
                        'channel' => 'Unsupported reminder channel.',
                    ]),
                };
            } catch (ValidationException $e) {
                $reason = collect($e->errors())->flatten()->first() ?: 'Channel delivery rejected.';

                return $this->markNotDeliverable($locked, is_string($reason) ? $reason : 'Channel delivery rejected.');
            }

            $locked->status = ReminderOccurrenceStatus::Queued;
            $locked->message_id = $message->id;
            $locked->queued_at = now();
            $locked->failure_reason = null;
            $locked->skip_reason = null;
            $locked->save();

            $this->auditLogger->record(
                event: 'reminder.queued',
                description: 'Invoice reminder queued for delivery',
                auditable: $locked,
                newValues: [
                    'invoice_id' => $invoice->id,
                    'rule_id' => $rule->id,
                    'channel' => $locked->channel->value,
                    'message_id' => $message->id,
                ],
            );

            return $locked->refresh();
        });
    }

    /**
     * @return list<CommunicationChannel>
     */
    private function channelsFor(Invoice $invoice): array
    {
        $contact = $invoice->contact;
        if ($contact === null) {
            return [];
        }

        $preference = $contact->reminder_channel instanceof ReminderChannelPreference
            ? $contact->reminder_channel
            : (ReminderChannelPreference::tryFrom((string) ($contact->reminder_channel ?? 'email'))
                ?? ReminderChannelPreference::Email);

        return $preference->channels();
    }

    private function claimOccurrence(
        Invoice $invoice,
        ReminderRule $rule,
        CommunicationChannel $channel,
        CarbonImmutable $today,
    ): ?ReminderOccurrence {
        $ineligible = $this->ineligibilityReason($invoice);
        if ($ineligible !== null) {
            return null;
        }

        try {
            return DB::transaction(function () use ($invoice, $rule, $channel, $today) {
                $existing = ReminderOccurrence::query()
                    ->where('invoice_id', $invoice->id)
                    ->where('reminder_rule_id', $rule->id)
                    ->where('channel', $channel->value)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return null;
                }

                return ReminderOccurrence::query()->create([
                    'invoice_id' => $invoice->id,
                    'reminder_rule_id' => $rule->id,
                    'channel' => $channel,
                    'occurrence_date' => $today->toDateString(),
                    'status' => ReminderOccurrenceStatus::Pending,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public function ineligibilityReason(Invoice $invoice): ?string
    {
        if ($invoice->lifecycle_status === InvoiceLifecycleStatus::Draft) {
            return 'Invoice is draft';
        }

        if ($invoice->lifecycle_status === InvoiceLifecycleStatus::Cancelled) {
            return 'Invoice is cancelled';
        }

        if (! $invoice->isIssued()) {
            return 'Invoice is not issued';
        }

        if ($invoice->due_date === null) {
            return 'Invoice has no due date';
        }

        if (! $invoice->payment_status->isOutstanding()) {
            return 'Invoice is fully paid';
        }

        if (Money::compare((string) $invoice->balance_due, '0') <= 0) {
            return 'Invoice has no outstanding balance';
        }

        return null;
    }

    private function channelIneligibility(Invoice $invoice, CommunicationChannel $channel): ?string
    {
        $contact = $invoice->contact;
        if ($contact === null) {
            return 'Invoice has no customer contact';
        }

        $business = Business::current();

        if ($channel === CommunicationChannel::Email) {
            if (! (bool) config('adman.email.enabled', true) || ! $business->outbound_email_enabled) {
                return 'Email delivery is disabled';
            }
            if (! filled($contact->email) || ! filter_var($contact->email, FILTER_VALIDATE_EMAIL)) {
                return 'Customer has no valid email address';
            }

            return null;
        }

        if ($channel === CommunicationChannel::WhatsApp) {
            if (! (bool) config('adman.whatsapp.enabled', true) || ! $business->outbound_whatsapp_enabled) {
                return 'WhatsApp delivery is disabled';
            }
            if (! $contact->whatsapp_opt_in) {
                return 'Customer has not opted in to WhatsApp';
            }
            if (WhatsAppPhone::fromContact($contact) === null) {
                return 'Customer has no valid WhatsApp number';
            }

            return null;
        }

        return 'Unsupported channel';
    }

    private function markSkipped(ReminderOccurrence $occurrence, string $reason): ReminderOccurrence
    {
        $occurrence->status = ReminderOccurrenceStatus::Skipped;
        $occurrence->skip_reason = $reason;
        $occurrence->completed_at = now();
        $occurrence->save();

        $this->auditLogger->record(
            event: 'reminder.skipped',
            description: 'Invoice reminder skipped',
            auditable: $occurrence,
            meta: ['reason' => $reason],
        );

        return $occurrence->refresh();
    }

    private function markNotDeliverable(ReminderOccurrence $occurrence, string $reason): ReminderOccurrence
    {
        $occurrence->status = ReminderOccurrenceStatus::NotDeliverable;
        $occurrence->failure_reason = $reason;
        $occurrence->completed_at = now();
        $occurrence->save();

        $this->auditLogger->record(
            event: 'reminder.failed',
            description: 'Invoice reminder not deliverable',
            auditable: $occurrence,
            meta: ['reason' => $reason],
        );

        return $occurrence->refresh();
    }

    /**
     * @param  list<array{id?: int, offset_days: int, is_enabled: bool}>  $rules
     */
    private function validateRulePayload(array $rules): void
    {
        if ($rules === []) {
            throw ValidationException::withMessages([
                'rules' => 'At least one reminder rule is required.',
            ]);
        }

        $seen = [];
        foreach ($rules as $index => $row) {
            if (! isset($row['offset_days']) || ! is_numeric($row['offset_days'])) {
                throw ValidationException::withMessages([
                    "rules.{$index}.offset_days" => 'Offset must be an integer number of days.',
                ]);
            }

            $offset = (int) $row['offset_days'];
            if ($offset < self::OFFSET_MIN || $offset > self::OFFSET_MAX) {
                throw ValidationException::withMessages([
                    "rules.{$index}.offset_days" => 'Offset must be between '.self::OFFSET_MIN.' and '.self::OFFSET_MAX.' days.',
                ]);
            }

            if (in_array($offset, $seen, true)) {
                throw ValidationException::withMessages([
                    'rules' => 'Duplicate reminder offsets are not allowed.',
                ]);
            }
            $seen[] = $offset;
        }
    }

    private function businessToday(Business $business): CarbonImmutable
    {
        $tz = $business->timezone ?: config('app.timezone', 'UTC');

        return CarbonImmutable::now($tz)->startOfDay();
    }

    private function systemActor(): User
    {
        $user = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', User::ROLE_SUPER_ADMINISTRATOR))
            ->orderBy('id')
            ->first();

        if ($user !== null) {
            return $user;
        }

        return User::query()->orderBy('id')->firstOrFail();
    }
}
