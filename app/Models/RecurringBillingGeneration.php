<?php

namespace App\Models;

use App\Enums\RecurringBillingDeliveryChannel;
use App\Enums\RecurringBillingGenerationStatus;
use Database\Factories\RecurringBillingGenerationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $schedule_id
 * @property string $period_key
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property RecurringBillingGenerationStatus $status
 * @property int|null $invoice_id
 * @property RecurringBillingDeliveryChannel|null $delivery_channel
 * @property int|null $document_id
 * @property string|null $pdf_failure_reason
 * @property string $trigger
 * @property Carbon|null $attempted_at
 * @property Carbon|null $completed_at
 * @property string|null $failure_reason
 * @property int|null $triggered_by
 */
class RecurringBillingGeneration extends Model
{
    /** @use HasFactory<RecurringBillingGenerationFactory> */
    use HasFactory;

    protected $fillable = [
        'schedule_id',
        'period_key',
        'period_start',
        'period_end',
        'status',
        'invoice_id',
        'delivery_channel',
        'document_id',
        'pdf_failure_reason',
        'trigger',
        'attempted_at',
        'completed_at',
        'failure_reason',
        'triggered_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => RecurringBillingGenerationStatus::class,
            'delivery_channel' => RecurringBillingDeliveryChannel::class,
            'attempted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RecurringBillingSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RecurringBillingSchedule::class, 'schedule_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return HasMany<RecurringBillingDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(RecurringBillingDelivery::class, 'generation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
