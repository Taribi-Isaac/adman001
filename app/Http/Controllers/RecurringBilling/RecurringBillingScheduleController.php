<?php

namespace App\Http\Controllers\RecurringBilling;

use App\Enums\ContactStatus;
use App\Enums\DiscountType;
use App\Enums\RecurringBillingFrequency;
use App\Enums\RecurringBillingGenerationStatus;
use App\Enums\RecurringBillingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecurringBilling\StoreRecurringBillingScheduleRequest;
use App\Http\Requests\RecurringBilling\UpdateRecurringBillingScheduleRequest;
use App\Models\Business;
use App\Models\Contact;
use App\Models\RecurringBillingGeneration;
use App\Models\RecurringBillingItem;
use App\Models\RecurringBillingSchedule;
use App\Services\RecurringBillingService;
use App\Support\DocumentCalculator;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RecurringBillingScheduleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permissions::RECURRING_BILLING_VIEW);

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');

        $schedules = RecurringBillingSchedule::query()
            ->with(['contact:id,display_name,status', 'items'])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like, $search) {
                    $inner->whereHas('contact', function ($contact) use ($like) {
                        $contact->where('display_name', 'like', $like)
                            ->orWhere('email', 'like', $like);
                    });

                    if (ctype_digit($search)) {
                        $inner->orWhere('id', (int) $search);
                    }
                });
            })
            ->when(
                is_string($status) && in_array($status, RecurringBillingStatus::values(), true),
                fn ($q) => $q->where('status', $status),
            )
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (RecurringBillingSchedule $schedule) => $this->listPayload($schedule));

        return Inertia::render('recurring-billing/Index', [
            'schedules' => $schedules,
            'filters' => [
                'search' => $search,
                'status' => is_string($status) ? $status : '',
            ],
            'statusOptions' => collect(RecurringBillingStatus::cases())->map(fn (RecurringBillingStatus $s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
            'canCreate' => auth()->user()?->can(Permissions::RECURRING_BILLING_CREATE) ?? false,
        ]);
    }

    public function create(): Response
    {
        $this->authorize(Permissions::RECURRING_BILLING_CREATE);

        return Inertia::render('recurring-billing/Create', [
            'customers' => $this->customerOptions(),
            'frequencyOptions' => $this->frequencyOptions(),
            'discountTypeOptions' => $this->discountTypeOptions(),
            'defaults' => $this->formDefaults(),
        ]);
    }

    public function store(
        StoreRecurringBillingScheduleRequest $request,
        RecurringBillingService $recurringBilling,
    ): RedirectResponse {
        $this->authorize(Permissions::RECURRING_BILLING_CREATE);

        $data = $request->validated();
        $items = $data['items'];
        unset($data['items']);

        try {
            $schedule = $recurringBilling->create($data, $items, $request->user());
        } catch (ValidationException $e) {
            throw $e;
        }

        return redirect()
            ->route('recurring-billing.show', $schedule)
            ->with('success', 'Recurring billing schedule created.');
    }

    public function show(RecurringBillingSchedule $schedule): Response
    {
        $this->authorize(Permissions::RECURRING_BILLING_VIEW);

        $schedule->load([
            'items',
            'contact',
            'generations' => fn ($q) => $q->with('invoice:id,number')->orderByDesc('id'),
        ]);

        $user = auth()->user();
        $amounts = $this->amountSummary($schedule);

        return Inertia::render('recurring-billing/Show', [
            'schedule' => $this->detailPayload($schedule, $amounts),
            'generations' => $schedule->generations->map(fn (RecurringBillingGeneration $generation) => [
                'id' => $generation->id,
                'period_key' => $generation->period_key,
                'period_start' => $generation->period_start?->toDateString(),
                'period_end' => $generation->period_end?->toDateString(),
                'status' => $generation->status->value,
                'status_label' => $generation->status->label(),
                'trigger' => $generation->trigger,
                'failure_reason' => $generation->failure_reason,
                'attempted_at' => $generation->attempted_at?->toIso8601String(),
                'completed_at' => $generation->completed_at?->toIso8601String(),
                'invoice' => $generation->invoice
                    ? ['id' => $generation->invoice->id, 'number' => $generation->invoice->number]
                    : null,
                'can_retry' => in_array($generation->status, [
                    RecurringBillingGenerationStatus::Failed,
                    RecurringBillingGenerationStatus::Skipped,
                ], true),
            ])->values()->all(),
            'permissions' => [
                'update' => ($user?->can(Permissions::RECURRING_BILLING_UPDATE) ?? false)
                    && $schedule->status !== RecurringBillingStatus::Cancelled,
                'pause' => ($user?->can(Permissions::RECURRING_BILLING_PAUSE) ?? false)
                    && $schedule->isActive(),
                'resume' => ($user?->can(Permissions::RECURRING_BILLING_RESUME) ?? false)
                    && $schedule->status === RecurringBillingStatus::Paused,
                'cancel' => ($user?->can(Permissions::RECURRING_BILLING_CANCEL) ?? false)
                    && $schedule->status !== RecurringBillingStatus::Cancelled,
                'generate' => ($user?->can(Permissions::RECURRING_BILLING_GENERATE) ?? false)
                    && $schedule->isActive(),
            ],
        ]);
    }

    public function edit(RecurringBillingSchedule $schedule): Response
    {
        $this->authorize(Permissions::RECURRING_BILLING_UPDATE);

        abort_if(
            $schedule->status === RecurringBillingStatus::Cancelled,
            403,
            'Cancelled schedules cannot be edited.',
        );

        $schedule->load(['items', 'contact']);
        $amounts = $this->amountSummary($schedule);

        return Inertia::render('recurring-billing/Edit', [
            'schedule' => $this->detailPayload($schedule, $amounts),
            'customers' => $this->customerOptions(),
            'frequencyOptions' => $this->frequencyOptions(),
            'discountTypeOptions' => $this->discountTypeOptions(),
        ]);
    }

    public function update(
        UpdateRecurringBillingScheduleRequest $request,
        RecurringBillingSchedule $schedule,
        RecurringBillingService $recurringBilling,
    ): RedirectResponse {
        $this->authorize(Permissions::RECURRING_BILLING_UPDATE);

        $data = $request->validated();
        $items = $data['items'];
        unset($data['items']);

        $recurringBilling->update($schedule, $data, $items, $request->user());

        return redirect()
            ->route('recurring-billing.show', $schedule)
            ->with('success', 'Schedule updated. Changes apply to future invoices only.');
    }

    public function pause(
        RecurringBillingSchedule $schedule,
        RecurringBillingService $recurringBilling,
        Request $request,
    ): RedirectResponse {
        $this->authorize(Permissions::RECURRING_BILLING_PAUSE);

        $recurringBilling->pause($schedule, $request->user());

        return back()->with('success', 'Schedule paused. No new invoices will be generated until resumed.');
    }

    public function resume(
        RecurringBillingSchedule $schedule,
        RecurringBillingService $recurringBilling,
        Request $request,
    ): RedirectResponse {
        $this->authorize(Permissions::RECURRING_BILLING_RESUME);

        $recurringBilling->resume($schedule, $request->user());

        return back()->with('success', 'Schedule resumed. Catch-up invoices are not generated for the pause period.');
    }

    public function cancel(
        RecurringBillingSchedule $schedule,
        RecurringBillingService $recurringBilling,
        Request $request,
    ): RedirectResponse {
        $this->authorize(Permissions::RECURRING_BILLING_CANCEL);

        $recurringBilling->cancel($schedule, $request->user());

        return back()->with('success', 'Schedule cancelled. Existing invoices are unchanged.');
    }

    public function generate(
        RecurringBillingSchedule $schedule,
        RecurringBillingService $recurringBilling,
        Request $request,
    ): RedirectResponse {
        $this->authorize(Permissions::RECURRING_BILLING_GENERATE);

        $generation = $recurringBilling->generateForSchedule($schedule, 'manual', $request->user());

        return $this->generationFlash($generation);
    }

    public function retryGeneration(
        RecurringBillingGeneration $generation,
        RecurringBillingService $recurringBilling,
        Request $request,
    ): RedirectResponse {
        $this->authorize(Permissions::RECURRING_BILLING_GENERATE);

        $generation = $recurringBilling->retryGeneration($generation, $request->user());

        return $this->generationFlash($generation, retried: true);
    }

    private function generationFlash(RecurringBillingGeneration $generation, bool $retried = false): RedirectResponse
    {
        $generation->loadMissing('invoice:id,number');
        $verb = $retried ? 'Retry' : 'Generation';

        return match ($generation->status) {
            RecurringBillingGenerationStatus::Succeeded => back()->with(
                'success',
                $verb.' succeeded. A new independent invoice was created'
                    .($generation->invoice?->number ? ' ('.$generation->invoice->number.').' : '.'),
            ),
            RecurringBillingGenerationStatus::Failed => back()->with(
                'error',
                $verb.' failed: '.($generation->failure_reason ?: 'Unknown error.'),
            ),
            RecurringBillingGenerationStatus::Skipped => back()->with(
                'success',
                $verb.' skipped: '.($generation->failure_reason ?: 'Not due yet.'),
            ),
            default => back()->with(
                'success',
                $verb.' is '.$generation->status->label().'.',
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayload(RecurringBillingSchedule $schedule): array
    {
        $amounts = $this->amountSummary($schedule);

        return [
            'id' => $schedule->id,
            'status' => $schedule->status->value,
            'status_label' => $schedule->status->label(),
            'frequency' => $schedule->frequency->value,
            'frequency_label' => $schedule->frequency->label(),
            'start_date' => $schedule->start_date?->toDateString(),
            'end_date' => $schedule->end_date?->toDateString(),
            'next_generation_date' => $schedule->next_generation_date?->toDateString(),
            'currency_code' => $schedule->currency_code,
            'preview_total' => $amounts['total'],
            'contact' => $schedule->contact ? [
                'id' => $schedule->contact->id,
                'display_name' => $schedule->contact->display_name,
            ] : null,
            'updated_at' => $schedule->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array{subtotal: string, discount_amount: string, tax_amount: string, total: string, lines: list<array{line_subtotal: string}>}  $amounts
     * @return array<string, mixed>
     */
    private function detailPayload(RecurringBillingSchedule $schedule, array $amounts): array
    {
        $lineTotals = $amounts['lines'];

        return [
            ...$this->listPayload($schedule),
            'payment_term_days' => $schedule->payment_term_days,
            'discount_type' => $schedule->discount_type->value,
            'discount_type_label' => $schedule->discount_type->label(),
            'discount_value' => $schedule->discount_value,
            'discount_amount' => $amounts['discount_amount'],
            'tax_enabled' => $schedule->tax_enabled,
            'tax_rate' => $schedule->tax_rate,
            'tax_amount' => $amounts['tax_amount'],
            'subtotal' => $amounts['subtotal'],
            'total' => $amounts['total'],
            'notes' => $schedule->notes,
            'terms' => $schedule->terms,
            'paused_at' => $schedule->paused_at?->toIso8601String(),
            'cancelled_at' => $schedule->cancelled_at?->toIso8601String(),
            'created_at' => $schedule->created_at?->toIso8601String(),
            'items' => $schedule->relationLoaded('items')
                ? $schedule->items->values()->map(function (RecurringBillingItem $item, int $index) use ($lineTotals) {
                    return [
                        'id' => $item->id,
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit' => $item->unit,
                        'unit_price' => $item->unit_price,
                        'line_subtotal' => $lineTotals[$index]['line_subtotal'] ?? '0.00',
                    ];
                })->all()
                : [],
            'contact_detail' => $schedule->contact ? [
                'id' => $schedule->contact->id,
                'display_name' => $schedule->contact->display_name,
                'email' => $schedule->contact->email,
                'phone' => $schedule->contact->phone,
                'status' => $schedule->contact->status->value,
                'status_label' => $schedule->contact->status->label(),
            ] : null,
        ];
    }

    /**
     * @return array{
     *     subtotal: string,
     *     discount_amount: string,
     *     tax_amount: string,
     *     total: string,
     *     lines: list<array{quantity: string, unit_price: string, line_subtotal: string}>
     * }
     */
    private function amountSummary(RecurringBillingSchedule $schedule): array
    {
        $items = $schedule->relationLoaded('items')
            ? $schedule->items
            : $schedule->items()->get();

        if ($items->isEmpty()) {
            return [
                'subtotal' => '0.00',
                'discount_amount' => '0.00',
                'tax_amount' => '0.00',
                'total' => '0.00',
                'lines' => [],
            ];
        }

        $calculated = DocumentCalculator::calculate(
            $items->map(fn (RecurringBillingItem $item) => [
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
            ])->all(),
            $schedule->discount_type,
            $schedule->discount_value,
            (bool) $schedule->tax_enabled,
            $schedule->tax_rate,
        );

        return [
            'subtotal' => $calculated['subtotal'],
            'discount_amount' => $calculated['discount_amount'],
            'tax_amount' => $calculated['tax_amount'],
            'total' => $calculated['total'],
            'lines' => $calculated['lines'],
        ];
    }

    /**
     * @return list<array{id: int, display_name: string, email: string|null}>
     */
    private function customerOptions(): array
    {
        return Contact::query()
            ->active()
            ->where('status', ContactStatus::Customer)
            ->orderBy('display_name')
            ->limit(300)
            ->get(['id', 'display_name', 'email'])
            ->map(fn (Contact $contact) => [
                'id' => $contact->id,
                'display_name' => $contact->display_name,
                'email' => $contact->email,
            ])
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function frequencyOptions(): array
    {
        return collect(RecurringBillingFrequency::cases())->map(fn (RecurringBillingFrequency $f) => [
            'value' => $f->value,
            'label' => $f->label(),
        ])->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function discountTypeOptions(): array
    {
        return collect(DiscountType::cases())->map(fn (DiscountType $t) => [
            'value' => $t->value,
            'label' => $t->label(),
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formDefaults(): array
    {
        $business = Business::current();

        return [
            'frequency' => RecurringBillingFrequency::Monthly->value,
            'start_date' => now()->toDateString(),
            'payment_term_days' => (int) ($business->default_payment_term_days ?: 14),
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => (bool) $business->tax_enabled,
            'tax_rate' => (string) ($business->tax_rate ?? '0'),
            'terms' => $business->default_terms,
            'currency_code' => $business->currency_code,
        ];
    }
}
