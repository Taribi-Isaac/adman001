<?php

namespace App\Services;

use App\Enums\PaymentClaimStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentClaim;
use App\Models\User;
use App\Support\DocumentNumbering;
use App\Support\DocumentSnapshots;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly DocumentNumbering $numbering,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(Invoice $invoice, array $data, User $actor, bool $confirmImmediately = false): Payment
    {
        return DB::transaction(function () use ($invoice, $data, $actor, $confirmImmediately) {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertInvoiceAcceptsPayments($locked);

            $amount = Money::normalize((string) $data['amount']);
            $this->assertPositiveAmount($amount);
            $this->assertFitsOutstanding($locked, $amount);

            $payment = Payment::query()->create([
                'number' => $this->numbering->nextReceiptNumber(),
                'invoice_id' => $locked->id,
                'contact_id' => $locked->contact_id,
                'amount' => $amount,
                'currency_code' => $locked->currency_code,
                'payment_method' => PaymentMethod::from((string) $data['payment_method']),
                'payment_date' => $data['payment_date'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => PaymentStatus::Pending,
                'recorded_by' => $actor->id,
            ]);

            $this->auditLogger->record(
                event: 'payment.recorded',
                description: 'Payment recorded',
                auditable: $payment,
                newValues: [
                    'number' => $payment->number,
                    'invoice_id' => $locked->id,
                    'amount' => $payment->amount,
                    'status' => PaymentStatus::Pending->value,
                ],
                actor: $actor,
            );

            if ($confirmImmediately) {
                return $this->confirmPayment($payment, $actor, withinTransaction: true);
            }

            return $payment->refresh();
        });
    }

    public function confirm(Payment $payment, User $actor): Payment
    {
        return DB::transaction(function () use ($payment, $actor) {
            return $this->confirmPayment($payment, $actor, withinTransaction: true);
        });
    }

    public function reject(Payment $payment, User $actor, ?string $notes = null): Payment
    {
        return DB::transaction(function () use ($payment, $actor, $notes) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Rejected) {
                return $locked;
            }

            if ($locked->status === PaymentStatus::Confirmed) {
                throw ValidationException::withMessages([
                    'status' => 'Confirmed payments cannot be rejected. A reversal process is not implemented.',
                ]);
            }

            $locked->status = PaymentStatus::Rejected;
            $locked->rejected_by = $actor->id;
            $locked->rejected_at = now();
            $locked->rejection_notes = $notes;
            $locked->save();

            $this->auditLogger->record(
                event: 'payment.rejected',
                description: 'Payment rejected',
                auditable: $locked,
                newValues: [
                    'status' => PaymentStatus::Rejected->value,
                    'notes' => $notes,
                ],
                actor: $actor,
            );

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createClaim(Invoice $invoice, array $data, ?User $actor = null): PaymentClaim
    {
        $this->assertInvoiceAcceptsPayments($invoice);

        $amount = Money::normalize((string) $data['claimed_amount']);
        $this->assertPositiveAmount($amount, 'claimed_amount');

        $claim = PaymentClaim::query()->create([
            'invoice_id' => $invoice->id,
            'contact_id' => $invoice->contact_id,
            'claimed_amount' => $amount,
            'claimed_payment_date' => $data['claimed_payment_date'] ?? null,
            'payment_method' => isset($data['payment_method']) && $data['payment_method'] !== null && $data['payment_method'] !== ''
                ? PaymentMethod::from((string) $data['payment_method'])
                : null,
            'customer_reference' => $data['customer_reference'] ?? null,
            'supporting_info' => $data['supporting_info'] ?? null,
            'source_channel' => $data['source_channel'] ?? 'staff',
            'status' => PaymentClaimStatus::PendingVerification,
            'created_by' => $actor?->id,
        ]);

        $this->auditLogger->record(
            event: 'payment_claim.created',
            description: 'Payment claim created',
            auditable: $claim,
            newValues: [
                'invoice_id' => $invoice->id,
                'claimed_amount' => $claim->claimed_amount,
                'status' => $claim->status->value,
            ],
            actor: $actor,
        );

        return $claim->refresh();
    }

    public function confirmClaim(PaymentClaim $claim, User $actor, ?string $reviewerNotes = null): Payment
    {
        return DB::transaction(function () use ($claim, $actor, $reviewerNotes) {
            /** @var PaymentClaim $lockedClaim */
            $lockedClaim = PaymentClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();

            if ($lockedClaim->status === PaymentClaimStatus::Confirmed && $lockedClaim->payment_id) {
                return Payment::query()->findOrFail($lockedClaim->payment_id);
            }

            if (! $lockedClaim->status->isOpen()) {
                throw ValidationException::withMessages([
                    'status' => 'Only open claims can be confirmed.',
                ]);
            }

            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($lockedClaim->invoice_id)->lockForUpdate()->firstOrFail();
            $this->assertInvoiceAcceptsPayments($invoice);

            $amount = Money::normalize((string) $lockedClaim->claimed_amount);
            $this->assertFitsOutstanding($invoice, $amount);

            $method = $lockedClaim->payment_method ?? PaymentMethod::Other;
            $paymentDate = $lockedClaim->claimed_payment_date?->toDateString()
                ?? DocumentSnapshots::businessToday()->toDateString();

            $payment = Payment::query()->create([
                'number' => $this->numbering->nextReceiptNumber(),
                'invoice_id' => $invoice->id,
                'contact_id' => $invoice->contact_id,
                'amount' => $amount,
                'currency_code' => $invoice->currency_code,
                'payment_method' => $method,
                'payment_date' => $paymentDate,
                'reference' => $lockedClaim->customer_reference,
                'notes' => $reviewerNotes,
                'status' => PaymentStatus::Pending,
                'recorded_by' => $actor->id,
            ]);

            $payment = $this->confirmPayment($payment, $actor, withinTransaction: true);

            $lockedClaim->status = PaymentClaimStatus::Confirmed;
            $lockedClaim->payment_id = $payment->id;
            $lockedClaim->reviewed_by = $actor->id;
            $lockedClaim->reviewed_at = now();
            $lockedClaim->reviewer_notes = $reviewerNotes;
            $lockedClaim->save();

            $this->auditLogger->record(
                event: 'payment_claim.confirmed',
                description: 'Payment claim confirmed; payment created',
                auditable: $lockedClaim,
                newValues: [
                    'status' => PaymentClaimStatus::Confirmed->value,
                    'payment_id' => $payment->id,
                    'payment_number' => $payment->number,
                ],
                actor: $actor,
            );

            return $payment;
        });
    }

    public function rejectClaim(PaymentClaim $claim, User $actor, ?string $reviewerNotes = null): PaymentClaim
    {
        return DB::transaction(function () use ($claim, $actor, $reviewerNotes) {
            /** @var PaymentClaim $locked */
            $locked = PaymentClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentClaimStatus::Rejected) {
                return $locked;
            }

            if ($locked->status === PaymentClaimStatus::Confirmed) {
                throw ValidationException::withMessages([
                    'status' => 'Confirmed claims cannot be rejected.',
                ]);
            }

            $locked->status = PaymentClaimStatus::Rejected;
            $locked->reviewed_by = $actor->id;
            $locked->reviewed_at = now();
            $locked->reviewer_notes = $reviewerNotes;
            $locked->save();

            $this->auditLogger->record(
                event: 'payment_claim.rejected',
                description: 'Payment claim rejected',
                auditable: $locked,
                newValues: [
                    'status' => PaymentClaimStatus::Rejected->value,
                    'notes' => $reviewerNotes,
                ],
                actor: $actor,
            );

            return $locked->refresh();
        });
    }

    public function requestClaimInformation(PaymentClaim $claim, User $actor, ?string $reviewerNotes = null): PaymentClaim
    {
        return DB::transaction(function () use ($claim, $actor, $reviewerNotes) {
            /** @var PaymentClaim $locked */
            $locked = PaymentClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpen() && $locked->status !== PaymentClaimStatus::AwaitingInformation) {
                throw ValidationException::withMessages([
                    'status' => 'Only open claims can request more information.',
                ]);
            }

            $locked->status = PaymentClaimStatus::AwaitingInformation;
            $locked->reviewed_by = $actor->id;
            $locked->reviewed_at = now();
            $locked->reviewer_notes = $reviewerNotes;
            $locked->save();

            $this->auditLogger->record(
                event: 'payment_claim.information_requested',
                description: 'More information requested on payment claim',
                auditable: $locked,
                newValues: [
                    'status' => PaymentClaimStatus::AwaitingInformation->value,
                    'notes' => $reviewerNotes,
                ],
                actor: $actor,
            );

            return $locked->refresh();
        });
    }

    private function confirmPayment(Payment $payment, User $actor, bool $withinTransaction = false): Payment
    {
        $run = function () use ($payment, $actor) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Confirmed) {
                return $locked->refresh();
            }

            if ($locked->status === PaymentStatus::Rejected) {
                throw ValidationException::withMessages([
                    'status' => 'Rejected payments cannot be confirmed.',
                ]);
            }

            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($locked->invoice_id)->lockForUpdate()->firstOrFail();
            $this->assertInvoiceAcceptsPayments($invoice);
            $this->assertFitsOutstanding($invoice, (string) $locked->amount, excludingPaymentId: $locked->id);

            $business = Business::current();
            $contact = $invoice->contact()->firstOrFail();

            $locked->status = PaymentStatus::Confirmed;
            $locked->confirmed_by = $actor->id;
            $locked->confirmed_at = now();
            $locked->business_snapshot = DocumentSnapshots::business($business);
            $locked->customer_snapshot = DocumentSnapshots::customer($contact);
            $locked->invoice_snapshot = [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'total' => (string) $invoice->total,
                'currency_code' => $invoice->currency_code,
                'amount_paid_before' => (string) $invoice->amount_paid,
                'balance_due_before' => (string) $invoice->balance_due,
            ];
            $locked->save();

            $invoice->unsetRelation('payments');
            $invoice->load('payments');
            $invoice->refreshBalanceFromPayments();
            $invoice->save();

            $locked->invoice_snapshot = array_merge($locked->invoice_snapshot ?? [], [
                'amount_paid_after' => (string) $invoice->amount_paid,
                'balance_due_after' => (string) $invoice->balance_due,
                'payment_status_after' => $invoice->payment_status->value,
            ]);
            $locked->save();

            $this->auditLogger->record(
                event: 'payment.confirmed',
                description: 'Payment confirmed',
                auditable: $locked,
                newValues: [
                    'status' => PaymentStatus::Confirmed->value,
                    'amount' => $locked->amount,
                    'invoice_id' => $invoice->id,
                    'invoice_payment_status' => $invoice->payment_status->value,
                    'balance_due' => $invoice->balance_due,
                ],
                actor: $actor,
            );

            return $locked->refresh();
        };

        return $withinTransaction ? $run() : DB::transaction($run);
    }

    private function assertInvoiceAcceptsPayments(Invoice $invoice): void
    {
        if ($invoice->isDraft()) {
            throw ValidationException::withMessages([
                'invoice_id' => 'Payments cannot be recorded against a draft invoice.',
            ]);
        }

        if ($invoice->isCancelled()) {
            throw ValidationException::withMessages([
                'invoice_id' => 'Payments cannot be recorded against a cancelled invoice.',
            ]);
        }

        if (! $invoice->isIssued()) {
            throw ValidationException::withMessages([
                'invoice_id' => 'Only issued invoices accept payments.',
            ]);
        }
    }

    private function assertPositiveAmount(string $amount, string $field = 'amount'): void
    {
        if (Money::compare($amount, '0') <= 0) {
            throw ValidationException::withMessages([
                $field => 'Amount must be greater than zero.',
            ]);
        }
    }

    private function assertFitsOutstanding(Invoice $invoice, string $amount, ?int $excludingPaymentId = null): void
    {
        $invoice->unsetRelation('payments');
        $invoice->load(['payments' => function ($q) use ($excludingPaymentId) {
            $q->where('status', PaymentStatus::Confirmed);
            if ($excludingPaymentId !== null) {
                $q->where('id', '!=', $excludingPaymentId);
            }
        }]);

        $paid = '0.00';
        foreach ($invoice->payments as $payment) {
            $paid = Money::add($paid, (string) $payment->amount);
        }

        $outstanding = Money::sub((string) $invoice->total, $paid);
        if (Money::isNegative($outstanding)) {
            $outstanding = '0.00';
        }

        if (Money::compare($amount, $outstanding) > 0) {
            throw ValidationException::withMessages([
                'amount' => 'Payment amount exceeds the outstanding balance of '.$outstanding.'.',
            ]);
        }
    }
}
