<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\InvoiceDueState;
use App\Enums\InvoiceLifecycleStatus;
use App\Enums\InvoicePaymentStatus;
use App\Enums\PaymentStatus;
use App\Support\DocumentSnapshots;
use App\Support\Money;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property int $contact_id
 * @property int|null $quote_id
 * @property InvoiceLifecycleStatus $lifecycle_status
 * @property InvoicePaymentStatus $payment_status
 * @property Carbon|null $issue_date
 * @property Carbon|null $due_date
 * @property string $currency_code
 * @property DiscountType $discount_type
 * @property string $discount_value
 * @property string $discount_amount
 * @property bool $tax_enabled
 * @property string|null $tax_name
 * @property string $tax_rate
 * @property string $tax_amount
 * @property string $subtotal
 * @property string $taxable_subtotal
 * @property string $total
 * @property string $amount_paid
 * @property string $balance_due
 * @property string|null $notes
 * @property string|null $terms
 * @property array<string, mixed>|null $business_snapshot
 * @property array<string, mixed>|null $customer_snapshot
 * @property Carbon|null $issued_at
 * @property Carbon|null $cancelled_at
 * @property int|null $created_by
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'number',
        'contact_id',
        'quote_id',
        'lifecycle_status',
        'payment_status',
        'issue_date',
        'due_date',
        'currency_code',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_enabled',
        'tax_name',
        'tax_rate',
        'tax_amount',
        'subtotal',
        'taxable_subtotal',
        'total',
        'amount_paid',
        'balance_due',
        'notes',
        'terms',
        'business_snapshot',
        'customer_snapshot',
        'issued_at',
        'cancelled_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lifecycle_status' => InvoiceLifecycleStatus::class,
            'payment_status' => InvoicePaymentStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'tax_enabled' => 'boolean',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'taxable_subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'business_snapshot' => 'array',
            'customer_snapshot' => 'array',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->lifecycle_status === InvoiceLifecycleStatus::Draft;
    }

    public function isIssued(): bool
    {
        return $this->lifecycle_status === InvoiceLifecycleStatus::Issued;
    }

    public function isCancelled(): bool
    {
        return $this->lifecycle_status === InvoiceLifecycleStatus::Cancelled;
    }

    public function dueState(?Carbon $businessToday = null): InvoiceDueState
    {
        if (! $this->isIssued() || $this->due_date === null) {
            return InvoiceDueState::NotApplicable;
        }

        if ($this->payment_status === InvoicePaymentStatus::Paid) {
            return InvoiceDueState::NotApplicable;
        }

        $today = ($businessToday ?? DocumentSnapshots::businessToday())->toDateString();
        $due = $this->due_date->toDateString();

        if ($due > $today) {
            return InvoiceDueState::NotDue;
        }

        if ($due === $today) {
            return InvoiceDueState::DueToday;
        }

        return InvoiceDueState::Overdue;
    }

    /**
     * UI badge precedence (documented):
     * Cancelled > Paid > Overdue > Due today > Partially paid > Unpaid > Issued > Draft
     */
    public function displayStatus(): array
    {
        if ($this->isCancelled()) {
            return ['key' => 'cancelled', 'label' => 'Cancelled'];
        }

        if ($this->isDraft()) {
            return ['key' => 'draft', 'label' => 'Draft'];
        }

        if ($this->payment_status === InvoicePaymentStatus::Paid) {
            return ['key' => 'paid', 'label' => 'Paid'];
        }

        $due = $this->dueState();
        if ($due === InvoiceDueState::Overdue) {
            return ['key' => 'overdue', 'label' => 'Overdue'];
        }
        if ($due === InvoiceDueState::DueToday) {
            return ['key' => 'due_today', 'label' => 'Due today'];
        }

        if ($this->payment_status === InvoicePaymentStatus::PartiallyPaid) {
            return ['key' => 'partially_paid', 'label' => 'Partially paid'];
        }

        if ($this->payment_status === InvoicePaymentStatus::Unpaid) {
            return ['key' => 'unpaid', 'label' => 'Unpaid'];
        }

        return ['key' => 'issued', 'label' => 'Issued'];
    }

    public function refreshBalanceFromPayments(): void
    {
        $paid = '0.00';

        $this->loadMissing('payments');

        foreach ($this->payments as $payment) {
            if ($payment->status === PaymentStatus::Confirmed) {
                $paid = Money::add($paid, (string) $payment->amount);
            }
        }

        $this->amount_paid = $paid;
        $balance = Money::sub((string) $this->total, $paid);

        if (Money::isNegative($balance)) {
            $balance = '0.00';
        }

        $this->balance_due = $balance;

        if (Money::compare($paid, '0') === 0) {
            $this->payment_status = InvoicePaymentStatus::Unpaid;
        } elseif (Money::compare($paid, (string) $this->total) >= 0) {
            $this->payment_status = InvoicePaymentStatus::Paid;
            $this->balance_due = '0.00';
        } else {
            $this->payment_status = InvoicePaymentStatus::PartiallyPaid;
        }
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<PaymentClaim, $this>
     */
    public function paymentClaims(): HasMany
    {
        return $this->hasMany(PaymentClaim::class);
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * @return HasMany<ReminderOccurrence, $this>
     */
    public function reminderOccurrences(): HasMany
    {
        return $this->hasMany(ReminderOccurrence::class);
    }
}
