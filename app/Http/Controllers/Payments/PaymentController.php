<?php

namespace App\Http\Controllers\Payments;

use App\Enums\CommunicationChannel;
use App\Enums\InvoiceLifecycleStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\RecordPaymentRequest;
use App\Http\Requests\Payments\RejectPaymentRequest;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\DocumentService;
use App\Services\PaymentService;
use App\Support\EmailDeliveryPresenter;
use App\Support\Money;
use App\Support\Permissions;
use App\Support\WhatsAppPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permissions::PAYMENTS_VIEW);

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $method = $request->query('method');
        $invoiceNumber = trim((string) $request->query('invoice', ''));
        $customer = trim((string) $request->query('customer', ''));

        $payments = Payment::query()
            ->with([
                'invoice:id,number,total,balance_due,currency_code',
                'contact:id,display_name',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('number', 'like', $like)
                        ->orWhere('reference', 'like', $like)
                        ->orWhereHas('invoice', fn ($q) => $q->where('number', 'like', $like))
                        ->orWhereHas('contact', function ($contact) use ($like) {
                            $contact->where('display_name', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        });
                });
            })
            ->when(
                is_string($status) && in_array($status, PaymentStatus::values(), true),
                fn ($q) => $q->where('status', $status),
            )
            ->when(
                is_string($method) && in_array($method, PaymentMethod::values(), true),
                fn ($q) => $q->where('payment_method', $method),
            )
            ->when($invoiceNumber !== '', function ($query) use ($invoiceNumber) {
                $like = '%'.$invoiceNumber.'%';
                $query->whereHas('invoice', fn ($q) => $q->where('number', 'like', $like));
            })
            ->when($customer !== '', function ($query) use ($customer) {
                $like = '%'.$customer.'%';
                $query->whereHas('contact', function ($contact) use ($like) {
                    $contact->where('display_name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Payment $payment) => $this->listPayload($payment));

        return Inertia::render('payments/Index', [
            'payments' => $payments,
            'filters' => [
                'search' => $search,
                'status' => is_string($status) ? $status : '',
                'method' => is_string($method) ? $method : '',
                'invoice' => $invoiceNumber,
                'customer' => $customer,
            ],
            'statusOptions' => collect(PaymentStatus::cases())->map(fn (PaymentStatus $s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
            'methodOptions' => collect(PaymentMethod::cases())->map(fn (PaymentMethod $m) => [
                'value' => $m->value,
                'label' => $m->label(),
            ]),
            'canRecord' => auth()->user()?->can(Permissions::PAYMENTS_RECORD) ?? false,
            'canViewClaims' => auth()->user()?->can(Permissions::PAYMENTS_CLAIMS_VIEW) ?? false,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize(Permissions::PAYMENTS_RECORD);

        $invoiceId = $request->query('invoice_id');
        $selectedInvoiceId = is_numeric($invoiceId) ? (int) $invoiceId : null;

        return Inertia::render('payments/Create', [
            'invoices' => $this->payableInvoiceOptions(),
            'methodOptions' => collect(PaymentMethod::cases())->map(fn (PaymentMethod $m) => [
                'value' => $m->value,
                'label' => $m->label(),
            ]),
            'selectedInvoiceId' => $selectedInvoiceId,
            'canConfirmImmediately' => auth()->user()?->can(Permissions::PAYMENTS_CONFIRM) ?? false,
            'defaults' => [
                'payment_date' => now()->toDateString(),
                'payment_method' => PaymentMethod::BankTransfer->value,
            ],
        ]);
    }

    public function store(RecordPaymentRequest $request, PaymentService $payments): RedirectResponse
    {
        $this->authorize(Permissions::PAYMENTS_RECORD);

        $data = $request->validated();
        $invoice = Invoice::query()->findOrFail($data['invoice_id']);
        unset($data['invoice_id']);

        $confirmImmediately = (bool) ($data['confirm_immediately'] ?? false);
        unset($data['confirm_immediately']);

        if ($confirmImmediately && ! ($request->user()?->can(Permissions::PAYMENTS_CONFIRM) ?? false)) {
            $confirmImmediately = false;
        }

        try {
            $payment = $payments->record($invoice, $data, $request->user(), $confirmImmediately);
        } catch (ValidationException $e) {
            throw $e;
        }

        $message = $confirmImmediately
            ? 'Payment recorded and confirmed.'
            : 'Payment recorded (pending confirmation).';

        return redirect()
            ->route('payments.show', $payment)
            ->with('success', $message);
    }

    public function show(Payment $payment): Response
    {
        $this->authorize(Permissions::PAYMENTS_VIEW);

        $payment->load([
            'invoice',
            'contact',
            'documents',
            'recorder:id,name',
            'confirmer:id,name',
            'claim:id,payment_id,status',
        ]);

        $user = auth()->user();
        $invoice = $payment->invoice;

        return Inertia::render('payments/Show', [
            'payment' => $this->detailPayload($payment),
            'documents' => $payment->documents->map(fn (Document $doc) => $this->documentPayload($doc)),
            'permissions' => [
                'confirm' => ($user?->can(Permissions::PAYMENTS_CONFIRM) ?? false) && $payment->isPending(),
                'reject' => ($user?->can(Permissions::PAYMENTS_REJECT) ?? false) && $payment->isPending(),
                'generate_acknowledgement' => ($user?->can(Permissions::PAYMENTS_ACKNOWLEDGEMENTS_GENERATE) ?? false)
                    && $payment->isConfirmed(),
                'revoke_link' => $user?->can(Permissions::DOCUMENTS_REVOKE_LINK) ?? false,
                'create_link' => $user?->can(Permissions::DOCUMENTS_GENERATE) ?? false,
                'send_email' => ($user?->can(Permissions::MESSAGES_SEND) ?? false)
                    && $payment->isConfirmed()
                    && filled($payment->contact?->email),
                'send_whatsapp' => ($user?->can(Permissions::MESSAGES_SEND) ?? false)
                    && $payment->isConfirmed()
                    && ($payment->contact?->whatsapp_opt_in ?? false)
                    && $payment->contact !== null
                    && WhatsAppPhone::fromContact($payment->contact) !== null,
            ],
            'emailDeliveries' => EmailDeliveryPresenter::recentFor($payment),
            'whatsappDeliveries' => EmailDeliveryPresenter::recentFor($payment, channel: CommunicationChannel::WhatsApp),
            'customerEmail' => $payment->contact?->email,
            'customerWhatsApp' => $payment->contact ? WhatsAppPhone::fromContact($payment->contact) : null,
            'invoiceContext' => $invoice ? [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'total' => $invoice->total,
                'amount_paid' => $invoice->amount_paid,
                'balance_due' => $invoice->balance_due,
                'currency_code' => $invoice->currency_code,
                'payment_status' => $invoice->payment_status->value,
                'payment_status_label' => $invoice->payment_status->label(),
            ] : null,
            'flashSecureUrl' => session('secure_url'),
        ]);
    }

    public function confirm(Payment $payment, PaymentService $payments): RedirectResponse
    {
        $this->authorize(Permissions::PAYMENTS_CONFIRM);

        try {
            $payments->confirm($payment, auth()->user());
        } catch (ValidationException $e) {
            throw $e;
        }

        return back()->with('success', 'Payment confirmed.');
    }

    public function reject(
        RejectPaymentRequest $request,
        Payment $payment,
        PaymentService $payments,
    ): RedirectResponse {
        $this->authorize(Permissions::PAYMENTS_REJECT);

        try {
            $payments->reject(
                $payment,
                $request->user(),
                $request->validated('rejection_notes'),
            );
        } catch (ValidationException $e) {
            throw $e;
        }

        return back()->with('success', 'Payment rejected.');
    }

    public function generateAcknowledgement(
        Payment $payment,
        DocumentService $documents,
    ): RedirectResponse {
        $this->authorize(Permissions::PAYMENTS_ACKNOWLEDGEMENTS_GENERATE);

        try {
            $result = $documents->generatePaymentAcknowledgementPdf($payment, auth()->user());
        } catch (ValidationException $e) {
            throw $e;
        }

        $plain = $result['plain_token'];
        $redirect = back()->with('success', 'Payment acknowledgement PDF generated.');

        if (is_string($plain) && $plain !== '') {
            $redirect->with('secure_url', url('/d/'.$plain));
        }

        return $redirect;
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayload(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'number' => $payment->number,
            'amount' => $payment->amount,
            'currency_code' => $payment->currency_code,
            'payment_method' => $payment->payment_method->value,
            'payment_method_label' => $payment->payment_method->label(),
            'payment_date' => $payment->payment_date?->toDateString(),
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            'reference' => $payment->reference,
            'invoice' => $payment->invoice ? [
                'id' => $payment->invoice->id,
                'number' => $payment->invoice->number,
            ] : null,
            'contact' => $payment->contact ? [
                'id' => $payment->contact->id,
                'display_name' => $payment->contact->display_name,
            ] : null,
            'updated_at' => $payment->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(Payment $payment): array
    {
        $balanceAfter = (string) ($payment->invoice_snapshot['balance_due_after']
            ?? $payment->invoice?->balance_due
            ?? '0');
        $isPartial = Money::compare($balanceAfter, '0') > 0;
        $coverage = $payment->isConfirmed()
            ? ($isPartial ? 'partial' : 'full')
            : null;

        return [
            ...$this->listPayload($payment),
            'notes' => $payment->notes,
            'rejection_notes' => $payment->rejection_notes,
            'confirmed_at' => $payment->confirmed_at?->toIso8601String(),
            'rejected_at' => $payment->rejected_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
            'coverage' => $coverage,
            'coverage_label' => match ($coverage) {
                'partial' => 'Partial payment',
                'full' => 'Full payment',
                default => null,
            },
            'balance_due_after' => $payment->isConfirmed() ? $balanceAfter : null,
            'amount_paid_after' => $payment->isConfirmed()
                ? (string) ($payment->invoice_snapshot['amount_paid_after'] ?? $payment->invoice?->amount_paid)
                : null,
            'recorder' => $payment->recorder ? [
                'id' => $payment->recorder->id,
                'name' => $payment->recorder->name,
            ] : null,
            'confirmer' => $payment->confirmer ? [
                'id' => $payment->confirmer->id,
                'name' => $payment->confirmer->name,
            ] : null,
            'claim' => $payment->relationLoaded('claim') && $payment->claim
                ? [
                    'id' => $payment->claim->id,
                    'status' => $payment->claim->status->value,
                    'status_label' => $payment->claim->status->label(),
                ]
                : null,
            'contact_detail' => $payment->contact ? [
                'id' => $payment->contact->id,
                'display_name' => $payment->contact->display_name,
                'email' => $payment->contact->email,
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
     * @return list<array<string, mixed>>
     */
    private function payableInvoiceOptions(): array
    {
        return Invoice::query()
            ->with('contact:id,display_name')
            ->where('lifecycle_status', InvoiceLifecycleStatus::Issued)
            ->where('balance_due', '>', 0)
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'total' => $invoice->total,
                'amount_paid' => $invoice->amount_paid,
                'balance_due' => $invoice->balance_due,
                'currency_code' => $invoice->currency_code,
                'contact' => $invoice->contact ? [
                    'id' => $invoice->contact->id,
                    'display_name' => $invoice->contact->display_name,
                ] : null,
            ])
            ->all();
    }
}
