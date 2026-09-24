<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\RecurringBillingFrequency;
use App\Enums\RecurringBillingGenerationStatus;
use App\Enums\RecurringBillingStatus;
use App\Models\Business;
use App\Models\Contact;
use App\Models\RecurringBillingGeneration;
use App\Models\RecurringBillingItem;
use App\Models\RecurringBillingSchedule;
use App\Models\User;
use App\Support\DocumentSnapshots;
use App\Support\RecurringBillingCalendar;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class RecurringBillingService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly InvoiceService $invoiceService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public function create(array $data, array $items, User $actor): RecurringBillingSchedule
    {
        $contact = $this->requireCustomer((int) $data['contact_id']);
        $business = Business::current();
        $this->assertItems($items);

        return DB::transaction(function () use ($data, $items, $actor, $contact, $business) {
            $start = $data['start_date'];
            $frequency = RecurringBillingFrequency::from((string) $data['frequency']);

            $schedule = RecurringBillingSchedule::query()->create([
                'business_id' => $business->id,
                'contact_id' => $contact->id,
                'frequency' => $frequency,
                'start_date' => $start,
                'end_date' => $data['end_date'] ?? null,
                'next_generation_date' => $start,
                'status' => RecurringBillingStatus::Active,
                'payment_term_days' => (int) ($data['payment_term_days'] ?? $business->default_payment_term_days ?: 14),
                'currency_code' => $business->currency_code,
                'discount_type' => DiscountType::from((string) ($data['discount_type'] ?? DiscountType::None->value)),
                'discount_value' => $data['discount_value'] ?? '0',
                'tax_enabled' => array_key_exists('tax_enabled', $data)
                    ? (bool) $data['tax_enabled']
                    : (bool) $business->tax_enabled,
                'tax_rate' => $data['tax_rate'] ?? $business->tax_rate,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? $business->default_terms,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->syncItems($schedule, $items);

            $this->auditLogger->record(
                event: 'recurring_billing.created',
                description: 'Recurring billing schedule created',
                auditable: $schedule,
                newValues: [
                    'contact_id' => $schedule->contact_id,
                    'frequency' => $schedule->frequency->value,
                    'start_date' => $schedule->start_date?->toDateString(),
                    'next_generation_date' => $schedule->next_generation_date?->toDateString(),
                ],
                actor: $actor,
            );

            return $schedule->load('items', 'contact');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public function update(RecurringBillingSchedule $schedule, array $data, array $items, User $actor): RecurringBillingSchedule
    {
        if ($schedule->status === RecurringBillingStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => 'Cancelled schedules cannot be updated.',
            ]);
        }

        $contact = $this->requireCustomer((int) ($data['contact_id'] ?? $schedule->contact_id));
        $this->assertItems($items);

        return DB::transaction(function () use ($schedule, $data, $items, $actor, $contact) {
            $old = [
                'discount_type' => $schedule->discount_type->value,
                'discount_value' => $schedule->discount_value,
                'payment_term_days' => $schedule->payment_term_days,
                'tax_enabled' => $schedule->tax_enabled,
                'tax_rate' => $schedule->tax_rate,
            ];

            $schedule->fill([
                'contact_id' => $contact->id,
                'frequency' => RecurringBillingFrequency::from((string) ($data['frequency'] ?? $schedule->frequency->value)),
                'end_date' => array_key_exists('end_date', $data) ? $data['end_date'] : $schedule->end_date,
                'payment_term_days' => (int) ($data['payment_term_days'] ?? $schedule->payment_term_days),
                'discount_type' => DiscountType::from((string) ($data['discount_type'] ?? $schedule->discount_type->value)),
                'discount_value' => $data['discount_value'] ?? $schedule->discount_value,
                'tax_enabled' => array_key_exists('tax_enabled', $data)
                    ? (bool) $data['tax_enabled']
                    : $schedule->tax_enabled,
                'tax_rate' => array_key_exists('tax_rate', $data) ? $data['tax_rate'] : $schedule->tax_rate,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $schedule->notes,
                'terms' => array_key_exists('terms', $data) ? $data['terms'] : $schedule->terms,
                'updated_by' => $actor->id,
            ]);
            $schedule->save();

            $schedule->items()->delete();
            $this->syncItems($schedule, $items);

            $this->auditLogger->record(
                event: 'recurring_billing.updated',
                description: 'Recurring billing schedule updated (future invoices only)',
                auditable: $schedule,
                oldValues: $old,
                newValues: [
                    'discount_type' => $schedule->discount_type->value,
                    'discount_value' => $schedule->discount_value,
                    'payment_term_days' => $schedule->payment_term_days,
                    'tax_enabled' => $schedule->tax_enabled,
                    'tax_rate' => $schedule->tax_rate,
                ],
                actor: $actor,
            );

            return $schedule->refresh()->load('items', 'contact');
        });
    }

    public function pause(RecurringBillingSchedule $schedule, User $actor): RecurringBillingSchedule
    {
        if (! $schedule->isActive()) {
            throw ValidationException::withMessages([
                'status' => 'Only active schedules can be paused.',
            ]);
        }

        $schedule->status = RecurringBillingStatus::Paused;
        $schedule->paused_at = now();
        $schedule->updated_by = $actor->id;
        $schedule->save();

        $this->auditLogger->record(
            event: 'recurring_billing.paused',
            description: 'Recurring billing schedule paused',
            auditable: $schedule,
            actor: $actor,
        );

        return $schedule->refresh();
    }

    public function resume(RecurringBillingSchedule $schedule, User $actor): RecurringBillingSchedule
    {
        if ($schedule->status !== RecurringBillingStatus::Paused) {
            throw ValidationException::withMessages([
                'status' => 'Only paused schedules can be resumed.',
            ]);
        }

        $business = Business::current();
        $today = DocumentSnapshots::businessToday($business);

        // No catch-up backlog: jump next generation to the next occurrence on/after today.
        $next = RecurringBillingCalendar::nextOnOrAfter(
            $today,
            $schedule->frequency,
            $schedule->preferredDay(),
        );

        if ($schedule->end_date !== null && $next->gt($schedule->end_date)) {
            $schedule->next_generation_date = null;
        } else {
            $schedule->next_generation_date = $next->toDateString();
        }

        $schedule->status = RecurringBillingStatus::Active;
        $schedule->paused_at = null;
        $schedule->updated_by = $actor->id;
        $schedule->save();

        $this->auditLogger->record(
            event: 'recurring_billing.resumed',
            description: 'Recurring billing schedule resumed (no catch-up backlog)',
            auditable: $schedule,
            newValues: [
                'next_generation_date' => $schedule->next_generation_date?->toDateString(),
            ],
            actor: $actor,
        );

        return $schedule->refresh();
    }

    public function cancel(RecurringBillingSchedule $schedule, User $actor): RecurringBillingSchedule
    {
        if ($schedule->status === RecurringBillingStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => 'Schedule is already cancelled.',
            ]);
        }

        $schedule->status = RecurringBillingStatus::Cancelled;
        $schedule->cancelled_at = now();
        $schedule->next_generation_date = null;
        $schedule->updated_by = $actor->id;
        $schedule->save();

        $this->auditLogger->record(
            event: 'recurring_billing.cancelled',
            description: 'Recurring billing schedule cancelled',
            auditable: $schedule,
            actor: $actor,
        );

        return $schedule->refresh();
    }

    /**
     * @return Collection<int, RecurringBillingGeneration>
     */
    public function processDueSchedules(?User $actor = null): Collection
    {
        $business = Business::current();
        $today = DocumentSnapshots::businessToday($business)->toDateString();

        $due = RecurringBillingSchedule::query()
            ->where('status', RecurringBillingStatus::Active)
            ->whereNotNull('next_generation_date')
            ->whereDate('next_generation_date', '<=', $today)
            ->orderBy('id')
            ->get();

        $results = collect();

        foreach ($due as $schedule) {
            $results->push($this->generateForSchedule($schedule, 'scheduler', $actor));
        }

        return $results;
    }

    public function generateForSchedule(
        RecurringBillingSchedule $schedule,
        string $trigger = 'scheduler',
        ?User $actor = null,
        ?string $forcedPeriodKey = null,
    ): RecurringBillingGeneration {
        $claim = $this->claimPeriod($schedule, $trigger, $actor, $forcedPeriodKey);

        if (isset($claim['done']) && $claim['done'] instanceof RecurringBillingGeneration) {
            return $claim['done'];
        }

        /** @var RecurringBillingGeneration $generation */
        $generation = $claim['generation'];
        /** @var RecurringBillingSchedule $locked */
        $locked = $claim['schedule'];
        /** @var array{period_key: string, period_start: string, period_end: string} $period */
        $period = $claim['period'];
        $generationDate = $claim['generation_date'];

        try {
            return DB::transaction(function () use ($generation, $locked, $period, $generationDate, $actor) {
                $generation = RecurringBillingGeneration::query()
                    ->whereKey($generation->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($generation->status === RecurringBillingGenerationStatus::Succeeded) {
                    return $generation->load('invoice');
                }

                $locked = RecurringBillingSchedule::query()
                    ->whereKey($locked->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $locked->load('items');

                $this->requireCustomer($locked->contact_id);

                $items = $locked->items->map(fn (RecurringBillingItem $item) => [
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                ])->all();

                if ($items === []) {
                    throw ValidationException::withMessages([
                        'items' => 'Schedule has no line items.',
                    ]);
                }

                $business = Business::current();
                $today = DocumentSnapshots::businessToday($business);
                $dueDate = $today->copy()->addDays((int) $locked->payment_term_days)->toDateString();

                $invoice = $this->invoiceService->createAndIssue([
                    'contact_id' => $locked->contact_id,
                    'due_date' => $dueDate,
                    'discount_type' => $locked->discount_type->value,
                    'discount_value' => $locked->discount_value,
                    'tax_enabled' => $locked->tax_enabled,
                    'tax_rate' => $locked->tax_rate,
                    'notes' => trim(($locked->notes ? $locked->notes."\n" : '').'Recurring billing period '.$period['period_key']),
                    'terms' => $locked->terms,
                ], $items, $actor);

                $generation->status = RecurringBillingGenerationStatus::Succeeded;
                $generation->invoice_id = $invoice->id;
                $generation->completed_at = now();
                $generation->failure_reason = null;
                $generation->save();

                $next = RecurringBillingCalendar::advance($generationDate, $locked->frequency);
                $next = RecurringBillingCalendar::nextOnOrAfter(
                    $next,
                    $locked->frequency,
                    $locked->preferredDay(),
                );

                if ($locked->end_date !== null && $next->gt($locked->end_date)) {
                    $locked->next_generation_date = null;
                } else {
                    $locked->next_generation_date = $next->toDateString();
                }
                $locked->save();

                $this->auditLogger->record(
                    event: 'recurring_billing.invoice_generated',
                    description: 'Recurring billing generated invoice',
                    auditable: $locked,
                    newValues: [
                        'period_key' => $period['period_key'],
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->number,
                        'next_generation_date' => $locked->next_generation_date?->toDateString(),
                    ],
                    actor: $actor,
                );

                return $generation->refresh()->load('invoice');
            });
        } catch (Throwable $e) {
            $generation->refresh();
            if ($generation->status !== RecurringBillingGenerationStatus::Succeeded) {
                $generation->status = RecurringBillingGenerationStatus::Failed;
                $generation->completed_at = now();
                $generation->failure_reason = $e->getMessage();
                $generation->save();

                $this->auditLogger->record(
                    event: 'recurring_billing.generation_failed',
                    description: 'Recurring billing generation failed',
                    auditable: $locked,
                    newValues: [
                        'period_key' => $period['period_key'],
                        'failure_reason' => $generation->failure_reason,
                    ],
                    actor: $actor,
                );
            }

            return $generation->refresh();
        }
    }

    public function retryGeneration(RecurringBillingGeneration $generation, User $actor): RecurringBillingGeneration
    {
        if ($generation->status === RecurringBillingGenerationStatus::Succeeded) {
            return $generation->load('invoice');
        }

        return $this->generateForSchedule(
            $generation->schedule,
            'retry',
            $actor,
            $generation->period_key,
        );
    }

    /**
     * @return array{
     *     done?: RecurringBillingGeneration,
     *     generation?: RecurringBillingGeneration,
     *     schedule?: RecurringBillingSchedule,
     *     period?: array{period_key: string, period_start: string, period_end: string},
     *     generation_date?: \Carbon\CarbonInterface
     * }
     */
    private function claimPeriod(
        RecurringBillingSchedule $schedule,
        string $trigger,
        ?User $actor,
        ?string $forcedPeriodKey,
    ): array {
        return DB::transaction(function () use ($schedule, $trigger, $actor, $forcedPeriodKey) {
            /** @var RecurringBillingSchedule $locked */
            $locked = RecurringBillingSchedule::query()
                ->whereKey($schedule->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== RecurringBillingStatus::Active) {
                return ['done' => $this->recordSkip($locked, $trigger, $actor, 'Schedule is not active.')];
            }

            $business = Business::current();
            $today = DocumentSnapshots::businessToday($business);

            $generationDate = $locked->next_generation_date ?? $today;

            if ($forcedPeriodKey === null) {
                if ($locked->next_generation_date === null) {
                    return ['done' => $this->recordSkip($locked, $trigger, $actor, 'No next generation date.')];
                }

                if ($generationDate->gt($today)) {
                    return ['done' => $this->recordSkip($locked, $trigger, $actor, 'Generation date is in the future.')];
                }
            }

            if ($locked->start_date->gt($today)) {
                return ['done' => $this->recordSkip($locked, $trigger, $actor, 'Schedule has not started.')];
            }

            if ($locked->end_date !== null && $generationDate->gt($locked->end_date)) {
                $locked->next_generation_date = null;
                $locked->save();

                return ['done' => $this->recordSkip($locked, $trigger, $actor, 'Past schedule end date.')];
            }

            $period = RecurringBillingCalendar::periodFor($generationDate, $locked->frequency);
            if ($forcedPeriodKey !== null) {
                $period['period_key'] = $forcedPeriodKey;
            }

            $existing = RecurringBillingGeneration::query()
                ->where('schedule_id', $locked->id)
                ->where('period_key', $period['period_key'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status === RecurringBillingGenerationStatus::Succeeded) {
                    return ['done' => $existing];
                }

                if ($existing->status === RecurringBillingGenerationStatus::Processing
                    && $existing->attempted_at !== null
                    && $existing->attempted_at->gt(now()->subMinutes(5))) {
                    return ['done' => $existing];
                }

                $existing->status = RecurringBillingGenerationStatus::Processing;
                $existing->attempted_at = now();
                $existing->failure_reason = null;
                $existing->trigger = $trigger;
                $existing->triggered_by = $actor?->id;
                $existing->completed_at = null;
                $existing->save();

                return [
                    'generation' => $existing,
                    'schedule' => $locked,
                    'period' => $period,
                    'generation_date' => $generationDate,
                ];
            }

            try {
                $generation = RecurringBillingGeneration::query()->create([
                    'schedule_id' => $locked->id,
                    'period_key' => $period['period_key'],
                    'period_start' => $period['period_start'],
                    'period_end' => $period['period_end'],
                    'status' => RecurringBillingGenerationStatus::Processing,
                    'trigger' => $trigger,
                    'attempted_at' => now(),
                    'triggered_by' => $actor?->id,
                ]);
            } catch (QueryException) {
                $winner = RecurringBillingGeneration::query()
                    ->where('schedule_id', $locked->id)
                    ->where('period_key', $period['period_key'])
                    ->firstOrFail();

                return ['done' => $winner];
            }

            return [
                'generation' => $generation,
                'schedule' => $locked,
                'period' => $period,
                'generation_date' => $generationDate,
            ];
        });
    }

    private function recordSkip(
        RecurringBillingSchedule $schedule,
        string $trigger,
        ?User $actor,
        string $reason,
    ): RecurringBillingGeneration {
        $date = $schedule->next_generation_date ?? DocumentSnapshots::businessToday();
        $period = RecurringBillingCalendar::periodFor($date, $schedule->frequency);

        $generation = RecurringBillingGeneration::query()->firstOrCreate(
            [
                'schedule_id' => $schedule->id,
                'period_key' => $period['period_key'],
            ],
            [
                'period_start' => $period['period_start'],
                'period_end' => $period['period_end'],
                'status' => RecurringBillingGenerationStatus::Skipped,
                'trigger' => $trigger,
                'attempted_at' => now(),
                'completed_at' => now(),
                'failure_reason' => $reason,
                'triggered_by' => $actor?->id,
            ],
        );

        if ($generation->status !== RecurringBillingGenerationStatus::Succeeded) {
            $generation->status = RecurringBillingGenerationStatus::Skipped;
            $generation->failure_reason = $reason;
            $generation->completed_at = now();
            $generation->save();
        }

        return $generation->refresh();
    }

    private function requireCustomer(int $contactId): Contact
    {
        $contact = Contact::query()->findOrFail($contactId);

        if ($contact->isArchived()) {
            throw ValidationException::withMessages([
                'contact_id' => 'Cannot use an archived contact for recurring billing.',
            ]);
        }

        if (! $contact->isCustomer()) {
            throw ValidationException::withMessages([
                'contact_id' => 'Recurring billing requires a Contact in the Customer lifecycle.',
            ]);
        }

        return $contact;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function assertItems(array $items): void
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'At least one line item is required.',
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function syncItems(RecurringBillingSchedule $schedule, array $items): void
    {
        foreach ($items as $index => $item) {
            RecurringBillingItem::query()->create([
                'schedule_id' => $schedule->id,
                'position' => $index,
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'] ?? null,
                'unit_price' => $item['unit_price'],
            ]);
        }
    }
}
