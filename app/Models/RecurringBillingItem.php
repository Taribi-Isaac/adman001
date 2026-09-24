<?php

namespace App\Models;

use Database\Factories\RecurringBillingItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $schedule_id
 * @property int $position
 * @property string $description
 * @property string $quantity
 * @property string|null $unit
 * @property string $unit_price
 */
class RecurringBillingItem extends Model
{
    /** @use HasFactory<RecurringBillingItemFactory> */
    use HasFactory;

    protected $fillable = [
        'schedule_id',
        'position',
        'description',
        'quantity',
        'unit',
        'unit_price',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<RecurringBillingSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RecurringBillingSchedule::class, 'schedule_id');
    }
}
