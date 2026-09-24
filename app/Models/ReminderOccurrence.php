<?php

namespace App\Models;

use App\Enums\CommunicationChannel;
use App\Enums\ReminderOccurrenceStatus;
use Database\Factories\ReminderOccurrenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One reminder attempt for an invoice/rule/channel (idempotent).
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $reminder_rule_id
 * @property CommunicationChannel $channel
 * @property Carbon $occurrence_date
 * @property ReminderOccurrenceStatus $status
 * @property int|null $message_id
 * @property string|null $failure_reason
 * @property string|null $skip_reason
 * @property Carbon|null $queued_at
 * @property Carbon|null $completed_at
 */
class ReminderOccurrence extends Model
{
    /** @use HasFactory<ReminderOccurrenceFactory> */
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'reminder_rule_id',
        'channel',
        'occurrence_date',
        'status',
        'message_id',
        'failure_reason',
        'skip_reason',
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
            'occurrence_date' => 'date',
            'status' => ReminderOccurrenceStatus::class,
            'queued_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<ReminderRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(ReminderRule::class, 'reminder_rule_id');
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
