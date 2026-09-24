<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Authoritative financial payment record (manual/offline MVP).
 *
 * @property int $id
 * @property string $number
 * @property int $invoice_id
 * @property int $contact_id
 * @property string $amount
 * @property string $currency_code
 * @property PaymentMethod $payment_method
 * @property Carbon $payment_date
 * @property string|null $reference
 * @property string|null $notes
 * @property PaymentStatus $status
 * @property int|null $recorded_by
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property int|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_notes
 * @property array<string, mixed>|null $business_snapshot
 * @property array<string, mixed>|null $customer_snapshot
 * @property array<string, mixed>|null $invoice_snapshot
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'number',
        'invoice_id',
        'contact_id',
        'amount',
        'currency_code',
        'payment_method',
        'payment_date',
        'reference',
        'notes',
        'status',
        'recorded_by',
        'confirmed_by',
        'confirmed_at',
        'rejected_by',
        'rejected_at',
        'rejection_notes',
        'business_snapshot',
        'customer_snapshot',
        'invoice_snapshot',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'payment_date' => 'date',
            'status' => PaymentStatus::class,
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'business_snapshot' => 'array',
            'customer_snapshot' => 'array',
            'invoice_snapshot' => 'array',
        ];
    }

    public function isConfirmed(): bool
    {
        return $this->status === PaymentStatus::Confirmed;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * @return HasOne<PaymentClaim, $this>
     */
    public function claim(): HasOne
    {
        return $this->hasOne(PaymentClaim::class);
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
