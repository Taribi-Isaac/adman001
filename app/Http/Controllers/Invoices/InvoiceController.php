<?php

namespace App\Http\Controllers\Invoices;

use App\Enums\CommunicationChannel;
use App\Enums\ContactStatus;
use App\Enums\DiscountType;
use App\Enums\InvoiceDueState;
use App\Enums\InvoiceLifecycleStatus;
use App\Enums\InvoicePaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoices\StoreInvoiceRequest;
use App\Http\Requests\Invoices\UpdateInvoiceRequest;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\DocumentService;
use App\Services\InvoiceService;
use App\Services\ReminderService;
use App\Support\DocumentSnapshots;
use App\Support\EmailDeliveryPresenter;
use App\Support\Money;
use App\Support\Permissions;
use App\Support\WhatsAppPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permissions::INVOICES_VIEW);

        $search = trim((string) $request->query('search', ''));
        $lifecycle = $request->query('lifecycle');
        $payment = $request->query('payment');
        $due = $request->query('due');
        $businessToday = DocumentSnapshots::businessToday()->toDateString();

        $invoices = Invoice::query()
            ->with(['contact:id,display_name,status'])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('number', 'like', $like)
                        ->orWhereHas('contact', function ($contact) use ($like) {
                            $contact->where('display_name', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        });
                });
            })
            ->when(
                is_string($lifecycle) && in_array($lifecycle, InvoiceLifecycleStatus::values(), true),
                fn ($q) => $q->where('lifecycle_status', $lifecycle),
            )
            ->when(
                is_string($payment) && in_array($payment, InvoicePaymentStatus::values(), true),
                fn ($q) => $q->where('payment_status', $payment),
            )
            ->when($due === 'overdue', function ($query) use ($businessToday) {
                $query->where('lifecycle_status', InvoiceLifecycleStatus::Issued)
                    ->whereIn('payment_status', [
                        InvoicePaymentStatus::Unpaid->value,
                        InvoicePaymentStatus::PartiallyPaid->value,
                    ])
                    ->where('balance_due', '>', 0)
                    ->whereDate('due_date', '<', $businessToday);
            })
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Invoice $invoice) => $this->listPayload($invoice));

        return Inertia::render('invoices/Index', [
            'invoices' => $invoices,
            'filters' => [
                'search' => $search,
                'lifecycle' => is_string($lifecycle) ? $lifecycle : '',
                'payment' => is_string($payment) ? $payment : '',
                'due' => $due === 'overdue' ? 'overdue' : '',
            ],
            'lifecycleOptions' => collect(InvoiceLifecycleStatus::cases())->map(fn (InvoiceLifecycleStatus $s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
            'paymentOptions' => collect(InvoicePaymentStatus::cases())->map(fn (InvoicePaymentStatus $s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
            'canCreate' => auth()->user()?->can(Permissions::INVOICES_CREATE) ?? false,
        ]);
    }

    public function create(): Response
    {
        $this->authorize(Permissions::INVOICES_CREATE);

        return Inertia::render('invoices/Create', [
            'customers' => $this->customerOptions(),
            'discountTypeOptions' => $this->discountTypeOptions(),
            'defaults' => $this->formDefaults(),
        ]);
    }

    public function store(StoreInvoiceRequest $request, InvoiceService $invoices): RedirectResponse
    {
        $this->authorize(Permissions::INVOICES_CREATE);

        $data = $request->validated();
        $items = $data['items'];
        unset($data['items']);

        try {
            $invoice = $invoices->create($data, $items, $request->user());
        } catch (ValidationException $e) {
            throw $e;
        }

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'Invoice created.');
    }

    public function show(Invoice $invoice): Response
    {
        $this->authorize(Permissions::INVOICES_VIEW);

        $invoice->load([
            'items',
            'contact',
            'quote:id,number',
            'documents',
            'payments' => fn ($q) => $q->orderByDesc('id'),
            'paymentClaims' => fn ($q) => $q->orderByDesc('id'),
            'reminderOccurrences' => fn ($q) => $q->with(['rule', 'message'])->orderByDesc('id'),
        ]);

        $user = auth()->user();
        $dueState = $invoice->dueState();
        $display = $invoice->displayStatus();

        $pendingClaims = $invoice->paymentClaims
            ->filter(fn ($claim) => $claim->status->isOpen())
            ->values();

        $reminderRules = app(ReminderService::class)->ensureDefaultRules();

        return Inertia::render('invoices/Show', [
            'invoice' => $this->detailPayload($invoice, $dueState, $display),
            'documents' => $invoice->documents->map(fn (Document $doc) => $this->documentPayload($doc)),
            'payments' => $invoice->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'number' => $payment->number,
                'amount' => $payment->amount,
                'currency_code' => $payment->currency_code,
                'payment_method_label' => $payment->payment_method->label(),
                'payment_date' => $payment->payment_date?->toDateString(),
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'reference' => $payment->reference,
            ])->values()->all(),
            'pendingClaims' => $pendingClaims->map(fn ($claim) => [
                'id' => $claim->id,
                'claimed_amount' => $claim->claimed_amount,
                'status' => $claim->status->value,
                'status_label' => $claim->status->label(),
                'claimed_payment_date' => $claim->claimed_payment_date?->toDateString(),
                'customer_reference' => $claim->customer_reference,
            ])->all(),
            'permissions' => [
                'update' => ($user?->can(Permissions::INVOICES_UPDATE) ?? false) && $invoice->isDraft(),
                'issue' => ($user?->can(Permissions::INVOICES_ISSUE) ?? false) && $invoice->isDraft(),
                'cancel' => ($user?->can(Permissions::INVOICES_CANCEL) ?? false) && ! $invoice->isCancelled(),
                'generate' => ($user?->can(Permissions::DOCUMENTS_GENERATE) ?? false)
                    && ! $invoice->isDraft(),
                'revoke_link' => $user?->can(Permissions::DOCUMENTS_REVOKE_LINK) ?? false,
                'record_payment' => ($user?->can(Permissions::PAYMENTS_RECORD) ?? false)
                    && $invoice->isIssued()
                    && Money::compare((string) $invoice->balance_due, '0') > 0,
                'view_payments' => $user?->can(Permissions::PAYMENTS_VIEW) ?? false,
                'view_claims' => $user?->can(Permissions::PAYMENTS_CLAIMS_VIEW) ?? false,
                'create_claim' => ($user?->can(Permissions::PAYMENTS_CLAIMS_CREATE) ?? false)
                    && $invoice->isIssued()
                    && Money::compare((string) $invoice->balance_due, '0') > 0,
                'send_email' => ($user?->can(Permissions::MESSAGES_SEND) ?? false)
                    && ! $invoice->isDraft()
                    && ! $invoice->isCancelled()
                    && filled($invoice->contact?->email),
                'send_whatsapp' => ($user?->can(Permissions::MESSAGES_SEND) ?? false)
                    && ! $invoice->isDraft()
                    && ! $invoice->isCancelled()
                    && ($invoice->contact?->whatsapp_opt_in ?? false)
                    && $invoice->contact !== null
                    && WhatsAppPhone::fromContact($invoice->contact) !== null,
            ],
            'emailDeliveries' => EmailDeliveryPresenter::recentFor($invoice),
            'whatsappDeliveries' => EmailDeliveryPresenter::recentFor($invoice, channel: CommunicationChannel::WhatsApp),
            'customerEmail' => $invoice->contact?->email,
            'customerWhatsApp' => $invoice->contact ? WhatsAppPhone::fromContact($invoice->contact) : null,
            'reminderRules' => $reminderRules->map(fn ($rule) => [
                'id' => $rule->id,
                'offset_days' => $rule->offset_days,
                'is_enabled' => $rule->is_enabled,
                'label' => $rule->label(),
            ])->values()->all(),
            'reminderOccurrences' => $invoice->reminderOccurrences->map(fn ($occurrence) => [
                'id' => $occurrence->id,
                'channel' => $occurrence->channel->value,
                'channel_label' => $occurrence->channel->label(),
                'status' => $occurrence->status->value,
                'status_label' => $occurrence->status->label(),
                'occurrence_date' => $occurrence->occurrence_date?->toDateString(),
                'rule_label' => $occurrence->rule?->label(),
                'offset_days' => $occurrence->rule?->offset_days,
                'failure_reason' => $occurrence->failure_reason,
                'skip_reason' => $occurrence->skip_reason,
                'message_id' => $occurrence->message_id,
                'message_status' => $occurrence->message?->status->label(),
                'queued_at' => $occurrence->queued_at?->toIso8601String(),
                'completed_at' => $occurrence->completed_at?->toIso8601String(),
            ])->values()->all(),
            'flashSecureUrl' => session('secure_url'),
        ]);
    }

    public function edit(Invoice $invoice): Response
    {
        $this->authorize(Permissions::INVOICES_UPDATE);

        abort_unless($invoice->isDraft(), 403, 'Only draft invoices can be edited.');

        $invoice->load(['items', 'contact']);

        return Inertia::render('invoices/Edit', [
            'invoice' => $this->detailPayload($invoice, $invoice->dueState(), $invoice->displayStatus()),
            'customers' => $this->customerOptions(),
            'discountTypeOptions' => $this->discountTypeOptions(),
        ]);
    }

    public function update(
        UpdateInvoiceRequest $request,
        Invoice $invoice,
        InvoiceService $invoices,
    ): RedirectResponse {
        $this->authorize(Permissions::INVOICES_UPDATE);

        $data = $request->validated();
        $items = $data['items'];
        unset($data['items']);

        $invoices->updateDraft($invoice, $data, $items);

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'Invoice updated.');
    }

    public function issue(Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $this->authorize(Permissions::INVOICES_ISSUE);

        $invoices->issue($invoice);

        return back()->with('success', 'Invoice issued.');
    }

    public function cancel(Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $this->authorize(Permissions::INVOICES_CANCEL);

        $invoices->cancel($invoice);

        return back()->with('success', 'Invoice cancelled.');
    }

    public function generateDocument(
        Invoice $invoice,
        DocumentService $documents,
    ): RedirectResponse {
        $this->authorize(Permissions::DOCUMENTS_GENERATE);

        $result = $documents->generateInvoicePdf($invoice, auth()->user());
        $plain = $result['plain_token'];

        $redirect = back()->with('success', 'Invoice PDF generated.');

        if (is_string($plain) && $plain !== '') {
            $redirect->with('secure_url', url('/d/'.$plain));
        }

        return $redirect;
    }

    /**
     * @param  array{key: string, label: string}|null  $display
     * @return array<string, mixed>
     */
    private function listPayload(Invoice $invoice, ?InvoiceDueState $dueState = null, ?array $display = null): array
    {
        $dueState ??= $invoice->dueState();
        $display ??= $invoice->displayStatus();

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'lifecycle_status' => $invoice->lifecycle_status->value,
            'lifecycle_status_label' => $invoice->lifecycle_status->label(),
            'payment_status' => $invoice->payment_status->value,
            'payment_status_label' => $invoice->payment_status->label(),
            'due_state' => $dueState->value,
            'due_state_label' => $dueState->label(),
            'display_status' => $display['key'],
            'display_status_label' => $display['label'],
            'total' => $invoice->total,
            'balance_due' => $invoice->balance_due,
            'currency_code' => $invoice->currency_code,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'contact' => $invoice->contact ? [
                'id' => $invoice->contact->id,
                'display_name' => $invoice->contact->display_name,
            ] : null,
            'updated_at' => $invoice->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array{key: string, label: string}  $display
     * @return array<string, mixed>
     */
    private function detailPayload(Invoice $invoice, InvoiceDueState $dueState, array $display): array
    {
        return [
            ...$this->listPayload($invoice, $dueState, $display),
            'discount_type' => $invoice->discount_type->value,
            'discount_type_label' => $invoice->discount_type->label(),
            'discount_value' => $invoice->discount_value,
            'discount_amount' => $invoice->discount_amount,
            'tax_enabled' => $invoice->tax_enabled,
            'tax_name' => $invoice->tax_name,
            'tax_rate' => $invoice->tax_rate,
            'tax_amount' => $invoice->tax_amount,
            'subtotal' => $invoice->subtotal,
            'taxable_subtotal' => $invoice->taxable_subtotal,
            'amount_paid' => $invoice->amount_paid,
            'notes' => $invoice->notes,
            'terms' => $invoice->terms,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'cancelled_at' => $invoice->cancelled_at?->toIso8601String(),
            'created_at' => $invoice->created_at?->toIso8601String(),
            'quote' => $invoice->relationLoaded('quote') && $invoice->quote
                ? ['id' => $invoice->quote->id, 'number' => $invoice->quote->number]
                : null,
            'items' => $invoice->relationLoaded('items')
                ? $invoice->items->map(fn (InvoiceItem $item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'line_subtotal' => $item->line_subtotal,
                ])->values()->all()
                : [],
            'contact_detail' => $invoice->contact ? [
                'id' => $invoice->contact->id,
                'display_name' => $invoice->contact->display_name,
                'email' => $invoice->contact->email,
                'phone' => $invoice->contact->phone,
                'status' => $invoice->contact->status->value,
                'status_label' => $invoice->contact->status->label(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentPayload(Document $document): array
    {
        return [
            'id' => $document->id,
            'type' => $document->type->value,
            'type_label' => $document->type->label(),
            'filename' => $document->filename,
            'generated_at' => $document->generated_at?->toIso8601String(),
            'has_active_link' => $document->isAccessActive(),
            'access_expires_at' => $document->access_expires_at?->toIso8601String(),
            'access_revoked_at' => $document->access_revoked_at?->toIso8601String(),
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
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => (bool) $business->tax_enabled,
            'tax_rate' => (string) ($business->tax_rate ?? '0'),
            'terms' => $business->default_terms,
            'currency_code' => $business->currency_code,
            'default_payment_term_days' => (int) ($business->default_payment_term_days ?: 14),
        ];
    }
}
