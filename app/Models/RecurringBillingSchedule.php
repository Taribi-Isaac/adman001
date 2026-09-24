<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\RecurringBillingFrequency;
use App\Enums\RecurringBillingStatus;
use Database\Factories\RecurringBillingScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Configuration that generates ordinary invoices on a schedule.
 *
 * @property int $id
 * @property int $business_id
 * @property int $contact_id
 * @property RecurringBillingFrequency $frequency
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property Carbon|null $next_generation_date
 * @property RecurringBillingStatus $status
 * @property int $payment_term_days
 * @property string $currency_code
 * @property DiscountType $discount_type
 * @property string $discount_value
 * @property bool $tax_enabled
 * @property string|null $tax_rate
 * @property string|null $notes
 * @property string|null $terms
 * @property Carbon|null $paused_at
 * @property Carbon|null $cancelled_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class RecurringBillingSchedule extends Model
{
    /** @use HasFactory<RecurringBillingScheduleFactory> */
    use HasFactory;

    protected $fillable = [
        'business_id',
        'contact_id',
        'frequency',
        'start_date',
        'end_date',
        'next_generation_date',
        'status',
        'payment_term_days',
        'currency_code',
        'discount_type',
        'discount_value',
        'tax_enabled',
        'tax_rate',
        'notes',
        'terms',
        'paused_at',
        'cancelled_at',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frequency' => RecurringBillingFrequency::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'next_generation_date' => 'date',
            'status' => RecurringBillingStatus::class,
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:4',
            'tax_enabled' => 'boolean',
            'tax_rate' => 'decimal:4',
            'paused_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === RecurringBillingStatus::Active;
    }

    public function preferredDay(): int
    {
        return (int) $this->start_date->day;
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<RecurringBillingItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RecurringBillingItem::class, 'schedule_id')->orderBy('position');
    }

    /**
     * @return HasMany<RecurringBillingGeneration, $this>
     */
    public function generations(): HasMany
    {
        return $this->hasMany(RecurringBillingGeneration::class, 'schedule_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
