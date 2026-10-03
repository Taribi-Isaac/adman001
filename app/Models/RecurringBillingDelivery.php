<?php

namespace App\Models;

use App\Enums\CommunicationChannel;
use App\Enums\RecurringBillingDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One logical customer delivery of a recurring invoice: unique per (generation, channel).
 *
 * @property int $id
 * @property int $generation_id
 * @property CommunicationChannel $channel
 * @property RecurringBillingDeliveryStatus $status
 * @property int|null $message_id
 * @property string|null $failure_reason
 * @property Carbon|null $queued_at
 * @property Carbon|null $completed_at
 */
class RecurringBillingDelivery extends Model
{
    protected $fillable = [
        'generation_id',
        'channel',
        'status',
        'message_id',
        'failure_reason',
        'queued_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => CommunicationChannel::class,
            'status' => RecurringBillingDeliveryStatus::class,
            'queued_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RecurringBillingGeneration, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(RecurringBillingGeneration::class, 'generation_id');
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
