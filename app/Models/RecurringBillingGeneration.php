<?php

namespace App\Models;

use App\Enums\RecurringBillingGenerationStatus;
use Database\Factories\RecurringBillingGenerationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $schedule_id
 * @property string $period_key
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property RecurringBillingGenerationStatus $status
 * @property int|null $invoice_id
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
     * @return BelongsTo<User, $this>
     */
    public function triggerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
