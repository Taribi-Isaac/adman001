<?php

namespace App\Models;

use Database\Factories\ReminderRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Business-wide invoice reminder offset rule (days relative to due date).
 *
 * @property int $id
 * @property int $business_id
 * @property int $offset_days
 * @property bool $is_enabled
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReminderRule extends Model
{
    /** @use HasFactory<ReminderRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'business_id',
        'offset_days',
        'is_enabled',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'offset_days' => 'integer',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function label(): string
    {
        if ($this->offset_days === 0) {
            return 'On due date';
        }

        if ($this->offset_days < 0) {
            $days = abs($this->offset_days);

            return $days.' day'.($days === 1 ? '' : 's').' before due date';
        }

        $days = $this->offset_days;

        return $days.' day'.($days === 1 ? '' : 's').' after due date';
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return HasMany<ReminderOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(ReminderOccurrence::class);
    }
}
