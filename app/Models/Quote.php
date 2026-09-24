<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\QuoteStatus;
use Database\Factories\QuoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property int $contact_id
 * @property QuoteStatus $status
 * @property Carbon|null $issue_date
 * @property Carbon|null $expiry_date
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
 * @property string|null $notes
 * @property string|null $terms
 * @property array<string, mixed>|null $business_snapshot
 * @property array<string, mixed>|null $customer_snapshot
 * @property Carbon|null $issued_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $cancelled_at
 * @property int|null $created_by
 */
class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
    use HasFactory;

    protected $fillable = [
        'number',
        'contact_id',
        'status',
        'issue_date',
        'expiry_date',
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
        'notes',
        'terms',
        'business_snapshot',
        'customer_snapshot',
        'issued_at',
        'accepted_at',
        'rejected_at',
        'cancelled_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'tax_enabled' => 'boolean',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'taxable_subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'business_snapshot' => 'array',
            'customer_snapshot' => 'array',
            'issued_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === QuoteStatus::Draft;
    }

    public function isIssued(): bool
    {
        return $this->status === QuoteStatus::Issued;
    }

    public function isAccepted(): bool
    {
        return $this->status === QuoteStatus::Accepted;
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<QuoteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('position');
    }

    /**
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
