<?php

namespace App\Models;

use App\Enums\PaymentClaimStatus;
use App\Enums\PaymentMethod;
use Database\Factories\PaymentClaimFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Customer assertion of payment — not financially authoritative until confirmed.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $contact_id
 * @property string $claimed_amount
 * @property Carbon|null $claimed_payment_date
 * @property PaymentMethod|null $payment_method
 * @property string|null $customer_reference
 * @property string|null $supporting_info
 * @property string|null $source_channel
 * @property PaymentClaimStatus $status
 * @property int|null $payment_id
 * @property int|null $created_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $reviewer_notes
 */
class PaymentClaim extends Model
{
    /** @use HasFactory<PaymentClaimFactory> */
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'contact_id',
        'claimed_amount',
        'claimed_payment_date',
        'payment_method',
        'customer_reference',
        'supporting_info',
        'source_channel',
        'status',
        'payment_id',
        'created_by',
        'reviewed_by',
        'reviewed_at',
        'reviewer_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'claimed_amount' => 'decimal:2',
            'claimed_payment_date' => 'date',
            'payment_method' => PaymentMethod::class,
            'status' => PaymentClaimStatus::class,
            'reviewed_at' => 'datetime',
        ];
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
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
