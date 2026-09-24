<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\QuoteStatus;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\User;
use App\Support\DocumentCalculator;
use App\Support\DocumentNumbering;
use App\Support\DocumentSnapshots;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuoteService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly DocumentNumbering $numbering,
        private readonly InvoiceService $invoiceService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public function create(array $data, array $items, User $actor): Quote
    {
        $contact = $this->requireCustomer((int) $data['contact_id']);
        $business = Business::current();
        $totals = $this->totalsFromInput($data, $items, $business);

        return DB::transaction(function () use ($data, $items, $actor, $contact, $business, $totals) {
            $quote = Quote::query()->create([
                'number' => $this->numbering->nextQuoteNumber($business),
                'contact_id' => $contact->id,
                'status' => QuoteStatus::Draft,
                'issue_date' => null,
                'expiry_date' => $data['expiry_date'] ?? null,
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
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? $business->default_terms,
                'created_by' => $actor->id,
            ]);

            $this->syncItems($quote, $items, $totals['lines']);

            $this->auditLogger->record(
                event: 'quote.created',
                description: 'Quote created',
                auditable: $quote,
                newValues: [
                    'number' => $quote->number,
                    'contact_id' => $quote->contact_id,
                    'total' => $quote->total,
                ],
                actor: $actor,
            );

            return $quote->load('items', 'contact');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public function updateDraft(Quote $quote, array $data, array $items): Quote
    {
        $this->assertDraft($quote);
        $contact = $this->requireCustomer((int) ($data['contact_id'] ?? $quote->contact_id));
        $business = Business::current();
        $totals = $this->totalsFromInput($data, $items, $business);

        return DB::transaction(function () use ($quote, $data, $items, $contact, $business, $totals) {
            $quote->fill([
                'contact_id' => $contact->id,
                'expiry_date' => $data['expiry_date'] ?? null,
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
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? $quote->terms,
            ]);
            $quote->save();

            $quote->items()->delete();
            $this->syncItems($quote, $items, $totals['lines']);

            return $quote->refresh()->load('items', 'contact');
        });
    }

    public function issue(Quote $quote): Quote
    {
        $this->assertDraft($quote);
        $this->requireCustomer($quote->contact_id);
        $business = Business::current();
        $today = DocumentSnapshots::businessToday($business);

        if ($quote->items()->count() === 0) {
            throw ValidationException::withMessages([
                'items' => 'Cannot issue a quote without line items.',
            ]);
        }

        return DB::transaction(function () use ($quote, $business, $today) {
            $contact = Contact::query()->findOrFail($quote->contact_id);

            $quote->status = QuoteStatus::Issued;
            $quote->issue_date = $today->toDateString();
            $quote->issued_at = now();
            $quote->business_snapshot = DocumentSnapshots::business($business);
            $quote->customer_snapshot = DocumentSnapshots::customer($contact);
            if ($quote->expiry_date === null) {
                $quote->expiry_date = $today->copy()->addDays(30)->toDateString();
            }
            $quote->save();

            $this->auditLogger->record(
                event: 'quote.issued',
                description: 'Quote issued',
                auditable: $quote,
                newValues: [
                    'number' => $quote->number,
                    'issue_date' => $quote->issue_date?->toDateString(),
                    'total' => $quote->total,
                ],
            );

            return $quote->refresh()->load('items', 'contact');
        });
    }

    public function accept(Quote $quote): Quote
    {
        $this->refreshExpiry($quote);

        if ($quote->status !== QuoteStatus::Issued) {
            throw ValidationException::withMessages([
                'status' => 'Only issued quotes can be accepted.',
            ]);
        }

        $quote->status = QuoteStatus::Accepted;
        $quote->accepted_at = now();
        $quote->save();

        $this->auditLogger->record(
            event: 'quote.accepted',
            description: 'Quote accepted',
            auditable: $quote,
            newValues: ['status' => QuoteStatus::Accepted->value],
        );

        return $quote->refresh();
    }

    public function reject(Quote $quote): Quote
    {
        $this->refreshExpiry($quote);

        if ($quote->status !== QuoteStatus::Issued) {
            throw ValidationException::withMessages([
                'status' => 'Only issued quotes can be rejected.',
            ]);
        }

        $quote->status = QuoteStatus::Rejected;
        $quote->rejected_at = now();
        $quote->save();

        $this->auditLogger->record(
            event: 'quote.rejected',
            description: 'Quote rejected',
            auditable: $quote,
            newValues: ['status' => QuoteStatus::Rejected->value],
        );

        return $quote->refresh();
    }

    public function cancel(Quote $quote): Quote
    {
        if (in_array($quote->status, [QuoteStatus::Cancelled, QuoteStatus::Accepted], true)) {
            throw ValidationException::withMessages([
                'status' => 'This quote cannot be cancelled.',
            ]);
        }

        if ($quote->invoice()->exists()) {
            throw ValidationException::withMessages([
                'status' => 'A quote that has been converted to an invoice cannot be cancelled.',
            ]);
        }

        $old = $quote->status->value;
        $quote->status = QuoteStatus::Cancelled;
        $quote->cancelled_at = now();
        $quote->save();

        $this->auditLogger->record(
            event: 'quote.cancelled',
            description: 'Quote cancelled',
            auditable: $quote,
            oldValues: ['status' => $old],
            newValues: ['status' => QuoteStatus::Cancelled->value],
        );

        return $quote->refresh();
    }

    /**
     * Convert an accepted quote into a draft invoice (idempotent: returns existing).
     */
    public function convertToInvoice(Quote $quote, User $actor): Invoice
    {
        $this->refreshExpiry($quote);

        if ($quote->status !== QuoteStatus::Accepted) {
            throw ValidationException::withMessages([
                'status' => 'Only accepted quotes can be converted to an invoice.',
            ]);
        }

        $existing = $quote->invoice;
        if ($existing !== null) {
            return $existing->load('items', 'contact');
        }

        return DB::transaction(function () use ($quote, $actor) {
            $invoice = $this->invoiceService->createFromQuote($quote, $actor);

            $this->auditLogger->record(
                event: 'quote.converted',
                description: 'Quote converted to invoice',
                auditable: $quote,
                newValues: [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->number,
                ],
                actor: $actor,
            );

            return $invoice;
        });
    }

    public function refreshExpiry(Quote $quote): void
    {
        if ($quote->status !== QuoteStatus::Issued || $quote->expiry_date === null) {
            return;
        }

        $today = DocumentSnapshots::businessToday()->toDateString();
        if ($quote->expiry_date->toDateString() < $today) {
            $quote->status = QuoteStatus::Expired;
            $quote->save();
        }
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
                'contact_id' => 'Quotes require a Contact in the Customer lifecycle. Promote the contact first.',
            ]);
        }

        return $contact;
    }

    private function assertDraft(Quote $quote): void
    {
        if (! $quote->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft quotes can be edited.',
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
    private function syncItems(Quote $quote, array $items, array $computedLines): void
    {
        foreach ($items as $index => $item) {
            QuoteItem::query()->create([
                'quote_id' => $quote->id,
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
