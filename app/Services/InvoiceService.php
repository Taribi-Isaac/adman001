<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\InvoiceLifecycleStatus;
use App\Enums\InvoicePaymentStatus;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Quote;
use App\Models\User;
use App\Support\DocumentCalculator;
use App\Support\DocumentNumbering;
use App\Support\DocumentSnapshots;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly DocumentNumbering $numbering,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public function create(array $data, array $items, ?User $actor = null): Invoice
    {
        $contact = $this->requireCustomer((int) $data['contact_id']);
        $business = Business::current();
        $totals = $this->totalsFromInput($data, $items, $business);

        return DB::transaction(function () use ($data, $items, $actor, $contact, $business, $totals) {
            $invoice = Invoice::query()->create([
                'number' => $this->numbering->nextInvoiceNumber($business),
                'contact_id' => $contact->id,
                'quote_id' => null,
                'lifecycle_status' => InvoiceLifecycleStatus::Draft,
                'payment_status' => InvoicePaymentStatus::Unpaid,
                'issue_date' => null,
                'due_date' => $data['due_date'] ?? null,
                'currency_code' => $business->currency_code,
                'discount_type' => DiscountType::from($totals['discount_type']),
                'discount_value' => $totals['discount_value'],
                'discount_amount' => $totals['discount_amount'],
                'tax_enabled' => $totals['tax_enabled'],
                'tax_name' => $totals['tax_enabled'] ? ($business->tax_name ?: 'Tax') : null,
                'tax_rate' => $totals['tax_rate'],
                'tax_amount' => $totals['tax_amount'],
                'subtotal' => $totals['subtotal'],
                'taxable_subtotal' => $totals['taxable_subtotal'],
                'total' => $totals['total'],
                'amount_paid' => '0.00',
                'balance_due' => $totals['total'],
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? $business->default_terms,
                'created_by' => $actor?->id,
            ]);

            $this->syncItems($invoice, $items, $totals['lines']);

            $this->auditLogger->record(
                event: 'invoice.created',
                description: 'Invoice created',
                auditable: $invoice,
                newValues: [
                    'number' => $invoice->number,
                    'contact_id' => $invoice->contact_id,
                    'total' => $invoice->total,
                ],
                actor: $actor,
            );

            return $invoice->load('items', 'contact');
        });
    }

    /**
     * Create and immediately issue an invoice (recurring billing / automation).
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public function createAndIssue(array $data, array $items, ?User $actor = null): Invoice
    {
        return DB::transaction(function () use ($data, $items, $actor) {
            $invoice = $this->create($data, $items, $actor);

            return $this->issue($invoice);
        });
    }

    public function createFromQuote(Quote $quote, User $actor): Invoice
    {
        if ($quote->invoice()->exists()) {
            return $quote->invoice()->with(['items', 'contact'])->firstOrFail();
        }

        $this->requireCustomer($quote->contact_id);
        $business = Business::current();
        $today = DocumentSnapshots::businessToday($business);
        $dueDays = (int) ($business->default_payment_term_days ?: 14);

        $quote->loadMissing('items');

        return DB::transaction(function () use ($quote, $actor, $business, $today, $dueDays) {
            $invoice = Invoice::query()->create([
                'number' => $this->numbering->nextInvoiceNumber($business),
                'contact_id' => $quote->contact_id,
                'quote_id' => $quote->id,
                'lifecycle_status' => InvoiceLifecycleStatus::Draft,
                'payment_status' => InvoicePaymentStatus::Unpaid,
                'issue_date' => null,
                'due_date' => $today->copy()->addDays($dueDays)->toDateString(),
                'currency_code' => $quote->currency_code,
                'discount_type' => $quote->discount_type,
                'discount_value' => $quote->discount_value,
                'discount_amount' => $quote->discount_amount,
                'tax_enabled' => $quote->tax_enabled,
                'tax_name' => $quote->tax_name,
                'tax_rate' => $quote->tax_rate,
                'tax_amount' => $quote->tax_amount,
                'subtotal' => $quote->subtotal,
                'taxable_subtotal' => $quote->taxable_subtotal,
                'total' => $quote->total,
                'amount_paid' => '0.00',
                'balance_due' => $quote->total,
                'notes' => $quote->notes,
                'terms' => $quote->terms ?? $business->default_terms,
                'created_by' => $actor->id,
            ]);

            foreach ($quote->items as $index => $item) {
                InvoiceItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'position' => $index,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'line_subtotal' => $item->line_subtotal,
                ]);
            }

            $this->auditLogger->record(
                event: 'invoice.created',
                description: 'Invoice created from quote',
                auditable: $invoice,
                newValues: [
                    'number' => $invoice->number,
                    'quote_id' => $quote->id,
                    'quote_number' => $quote->number,
                    'total' => $invoice->total,
                ],
                actor: $actor,
            );

            return $invoice->load('items', 'contact');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public function updateDraft(Invoice $invoice, array $data, array $items): Invoice
    {
        $this->assertDraft($invoice);
        $contact = $this->requireCustomer((int) ($data['contact_id'] ?? $invoice->contact_id));
        $business = Business::current();
        $totals = $this->totalsFromInput($data, $items, $business);

        return DB::transaction(function () use ($invoice, $data, $items, $contact, $business, $totals) {
            $invoice->fill([
                'contact_id' => $contact->id,
                'due_date' => $data['due_date'] ?? $invoice->due_date,
                'currency_code' => $business->currency_code,
                'discount_type' => DiscountType::from($totals['discount_type']),
                'discount_value' => $totals['discount_value'],
                'discount_amount' => $totals['discount_amount'],
                'tax_enabled' => $totals['tax_enabled'],
                'tax_name' => $totals['tax_enabled'] ? ($business->tax_name ?: 'Tax') : null,
                'tax_rate' => $totals['tax_rate'],
                'tax_amount' => $totals['tax_amount'],
                'subtotal' => $totals['subtotal'],
                'taxable_subtotal' => $totals['taxable_subtotal'],
                'total' => $totals['total'],
                'amount_paid' => '0.00',
                'balance_due' => $totals['total'],
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? $invoice->terms,
            ]);
            $invoice->save();

            $invoice->items()->delete();
            $this->syncItems($invoice, $items, $totals['lines']);

            return $invoice->refresh()->load('items', 'contact');
        });
    }

    public function issue(Invoice $invoice): Invoice
    {
        $this->assertDraft($invoice);
        $this->requireCustomer($invoice->contact_id);
        $business = Business::current();
        $today = DocumentSnapshots::businessToday($business);

        if ($invoice->items()->count() === 0) {
            throw ValidationException::withMessages([
                'items' => 'Cannot issue an invoice without line items.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $business, $today) {
            $contact = Contact::query()->findOrFail($invoice->contact_id);
            $dueDays = (int) ($business->default_payment_term_days ?: 14);

            $invoice->lifecycle_status = InvoiceLifecycleStatus::Issued;
            $invoice->payment_status = InvoicePaymentStatus::Unpaid;
            $invoice->issue_date = $today->toDateString();
            $invoice->issued_at = now();
            if ($invoice->due_date === null) {
                $invoice->due_date = $today->copy()->addDays($dueDays)->toDateString();
            }
            $invoice->amount_paid = '0.00';
            $invoice->balance_due = $invoice->total;
            $invoice->business_snapshot = DocumentSnapshots::business($business);
            $invoice->customer_snapshot = DocumentSnapshots::customer($contact);
            $invoice->save();

            $this->auditLogger->record(
                event: 'invoice.issued',
                description: 'Invoice issued',
                auditable: $invoice,
                newValues: [
                    'number' => $invoice->number,
                    'issue_date' => $invoice->issue_date?->toDateString(),
                    'due_date' => $invoice->due_date?->toDateString(),
                    'total' => $invoice->total,
                    'payment_status' => InvoicePaymentStatus::Unpaid->value,
                ],
            );

            return $invoice->refresh()->load('items', 'contact');
        });
    }

    public function cancel(Invoice $invoice): Invoice
    {
        if ($invoice->isCancelled()) {
            throw ValidationException::withMessages([
                'lifecycle_status' => 'Invoice is already cancelled.',
            ]);
        }

        if (Money::compare((string) $invoice->amount_paid, '0') > 0) {
            throw ValidationException::withMessages([
                'lifecycle_status' => 'Cannot cancel an invoice with recorded payments.',
            ]);
        }

        $old = $invoice->lifecycle_status->value;
        $invoice->lifecycle_status = InvoiceLifecycleStatus::Cancelled;
        $invoice->cancelled_at = now();
        $invoice->save();

        $this->auditLogger->record(
            event: 'invoice.cancelled',
            description: 'Invoice cancelled',
            auditable: $invoice,
            oldValues: ['lifecycle_status' => $old],
            newValues: ['lifecycle_status' => InvoiceLifecycleStatus::Cancelled->value],
        );

        return $invoice->refresh();
    }

    private function requireCustomer(int $contactId): Contact
    {
        $contact = Contact::query()->findOrFail($contactId);

        if ($contact->isArchived()) {
            throw ValidationException::withMessages([
                'contact_id' => 'Cannot use an archived contact for commercial documents.',
            ]);
        }

        if (! $contact->isCustomer()) {
            throw ValidationException::withMessages([
                'contact_id' => 'Invoices require a Contact in the Customer lifecycle. Promote the contact first.',
            ]);
        }

        return $contact;
    }

    private function assertDraft(Invoice $invoice): void
    {
        if (! $invoice->isDraft()) {
            throw ValidationException::withMessages([
                'lifecycle_status' => 'Only draft invoices can be edited.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function totalsFromInput(array $data, array $items, Business $business): array
    {
        $discountType = DiscountType::from((string) ($data['discount_type'] ?? DiscountType::None->value));
        $taxEnabled = array_key_exists('tax_enabled', $data)
            ? (bool) $data['tax_enabled']
            : (bool) $business->tax_enabled;
        $taxRate = $taxEnabled
            ? (string) ($data['tax_rate'] ?? $business->tax_rate ?? '0')
            : '0';

        return DocumentCalculator::calculate(
            lines: array_map(fn (array $item) => [
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
            ], $items),
            discountType: $discountType,
            discountValue: (string) ($data['discount_value'] ?? '0'),
            taxEnabled: $taxEnabled,
            taxRate: $taxRate,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array{quantity: string, unit_price: string, line_subtotal: string}>  $computedLines
     */
    private function syncItems(Invoice $invoice, array $items, array $computedLines): void
    {
        foreach ($items as $index => $item) {
            InvoiceItem::query()->create([
                'invoice_id' => $invoice->id,
                'position' => $index,
                'description' => $item['description'],
                'quantity' => $computedLines[$index]['quantity'],
                'unit' => $item['unit'] ?? null,
                'unit_price' => $computedLines[$index]['unit_price'],
                'line_subtotal' => $computedLines[$index]['line_subtotal'],
            ]);
        }
    }
}
