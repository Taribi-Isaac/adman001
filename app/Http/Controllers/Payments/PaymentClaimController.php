<?php

namespace App\Http\Controllers\Payments;

use App\Enums\InvoiceLifecycleStatus;
use App\Enums\PaymentClaimStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\ReviewClaimRequest;
use App\Http\Requests\Payments\StorePaymentClaimRequest;
use App\Models\Invoice;
use App\Models\PaymentClaim;
use App\Services\PaymentService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PaymentClaimController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permissions::PAYMENTS_CLAIMS_VIEW);

        $status = $request->query('status');
        $search = trim((string) $request->query('search', ''));

        $claims = PaymentClaim::query()
            ->with([
                'invoice:id,number,total,amount_paid,balance_due,currency_code',
                'contact:id,display_name',
            ])
            ->when(
                is_string($status) && in_array($status, PaymentClaimStatus::values(), true),
                fn ($q) => $q->where('status', $status),
            )
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('customer_reference', 'like', $like)
                        ->orWhereHas('invoice', fn ($q) => $q->where('number', 'like', $like))
                        ->orWhereHas('contact', function ($contact) use ($like) {
                            $contact->where('display_name', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        });
                });
            })
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PaymentClaim $claim) => $this->listPayload($claim));

        return Inertia::render('payment-claims/Index', [
            'claims' => $claims,
            'filters' => [
                'status' => is_string($status) ? $status : '',
                'search' => $search,
            ],
            'statusOptions' => collect(PaymentClaimStatus::cases())->map(fn (PaymentClaimStatus $s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
            'canCreate' => auth()->user()?->can(Permissions::PAYMENTS_CLAIMS_CREATE) ?? false,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize(Permissions::PAYMENTS_CLAIMS_CREATE);

        $invoiceId = $request->query('invoice_id');
        $selectedInvoiceId = is_numeric($invoiceId) ? (int) $invoiceId : null;

        return Inertia::render('payment-claims/Create', [
            'invoices' => $this->claimableInvoiceOptions(),
            'methodOptions' => collect(PaymentMethod::cases())->map(fn (PaymentMethod $m) => [
                'value' => $m->value,
                'label' => $m->label(),
            ]),
            'selectedInvoiceId' => $selectedInvoiceId,
            'defaults' => [
                'claimed_payment_date' => now()->toDateString(),
                'source_channel' => 'staff',
            ],
        ]);
    }

    public function store(StorePaymentClaimRequest $request, PaymentService $payments): RedirectResponse
    {
        $this->authorize(Permissions::PAYMENTS_CLAIMS_CREATE);

        $data = $request->validated();
        $invoice = Invoice::query()->findOrFail($data['invoice_id']);
        unset($data['invoice_id']);

        try {
            $claim = $payments->createClaim($invoice, $data, $request->user());
        } catch (ValidationException $e) {
            throw $e;
        }

        return redirect()
            ->route('payment-claims.show', $claim)
            ->with('success', 'Payment claim created. This is not yet a confirmed payment.');
    }

    public function show(PaymentClaim $paymentClaim): Response
    {
        $this->authorize(Permissions::PAYMENTS_CLAIMS_VIEW);

        $paymentClaim->load([
            'invoice',
            'contact',
            'payment:id,number,status',
            'creator:id,name',
            'reviewer:id,name',
        ]);

        $user = auth()->user();
        $invoice = $paymentClaim->invoice;
        $isOpen = $paymentClaim->status->isOpen();

        return Inertia::render('payment-claims/Show', [
            'claim' => $this->detailPayload($paymentClaim),
            'invoiceTotals' => $invoice ? [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'total' => $invoice->total,
                'amount_paid' => $invoice->amount_paid,
                'balance_due' => $invoice->balance_due,
                'currency_code' => $invoice->currency_code,
                'payment_status_label' => $invoice->payment_status->label(),
            ] : null,
            'permissions' => [
                'confirm' => ($user?->can(Permissions::PAYMENTS_CLAIMS_REVIEW) ?? false) && $isOpen,
                'reject' => ($user?->can(Permissions::PAYMENTS_CLAIMS_REVIEW) ?? false) && $isOpen,
                'request_information' => ($user?->can(Permissions::PAYMENTS_CLAIMS_REVIEW) ?? false) && $isOpen,
            ],
        ]);
    }

    public function confirm(
        ReviewClaimRequest $request,
        PaymentClaim $paymentClaim,
        PaymentService $payments,
    ): RedirectResponse {
        $this->authorize(Permissions::PAYMENTS_CLAIMS_REVIEW);

        try {
            $payment = $payments->confirmClaim(
                $paymentClaim,
                $request->user(),
                $request->validated('reviewer_notes'),
            );
        } catch (ValidationException $e) {
            throw $e;
        }

        return redirect()
            ->route('payments.show', $payment)
            ->with('success', 'Claim confirmed. A confirmed payment was created.');
    }

    public function reject(
        ReviewClaimRequest $request,
        PaymentClaim $paymentClaim,
        PaymentService $payments,
    ): RedirectResponse {
        $this->authorize(Permissions::PAYMENTS_CLAIMS_REVIEW);

        try {
            $payments->rejectClaim(
                $paymentClaim,
                $request->user(),
                $request->validated('reviewer_notes'),
            );
        } catch (ValidationException $e) {
            throw $e;
        }

        return back()->with('success', 'Payment claim rejected.');
    }

    public function requestInformation(
        ReviewClaimRequest $request,
        PaymentClaim $paymentClaim,
        PaymentService $payments,
    ): RedirectResponse {
        $this->authorize(Permissions::PAYMENTS_CLAIMS_REVIEW);

        try {
            $payments->requestClaimInformation(
                $paymentClaim,
                $request->user(),
                $request->validated('reviewer_notes'),
            );
        } catch (ValidationException $e) {
            throw $e;
        }

        return back()->with('success', 'More information requested on this claim.');
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayload(PaymentClaim $claim): array
    {
        return [
            'id' => $claim->id,
            'claimed_amount' => $claim->claimed_amount,
            'claimed_payment_date' => $claim->claimed_payment_date?->toDateString(),
            'payment_method' => $claim->payment_method?->value,
            'payment_method_label' => $claim->payment_method?->label(),
            'customer_reference' => $claim->customer_reference,
            'status' => $claim->status->value,
            'status_label' => $claim->status->label(),
            'source_channel' => $claim->source_channel,
            'invoice' => $claim->invoice ? [
                'id' => $claim->invoice->id,
                'number' => $claim->invoice->number,
                'currency_code' => $claim->invoice->currency_code,
            ] : null,
            'contact' => $claim->contact ? [
                'id' => $claim->contact->id,
                'display_name' => $claim->contact->display_name,
            ] : null,
            'created_at' => $claim->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(PaymentClaim $claim): array
    {
        return [
            ...$this->listPayload($claim),
            'supporting_info' => $claim->supporting_info,
            'reviewer_notes' => $claim->reviewer_notes,
            'reviewed_at' => $claim->reviewed_at?->toIso8601String(),
            'is_financially_confirmed' => $claim->status === PaymentClaimStatus::Confirmed,
            'creator' => $claim->creator ? [
                'id' => $claim->creator->id,
                'name' => $claim->creator->name,
            ] : null,
            'reviewer' => $claim->reviewer ? [
                'id' => $claim->reviewer->id,
                'name' => $claim->reviewer->name,
            ] : null,
            'payment' => $claim->payment ? [
                'id' => $claim->payment->id,
                'number' => $claim->payment->number,
                'status' => $claim->payment->status->value,
                'status_label' => $claim->payment->status->label(),
            ] : null,
            'contact_detail' => $claim->contact ? [
                'id' => $claim->contact->id,
                'display_name' => $claim->contact->display_name,
                'email' => $claim->contact->email,
            ] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function claimableInvoiceOptions(): array
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
