<?php

namespace App\Models;

use App\Enums\AiProcessingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Idempotent AI processing claim for an inbound message.
 *
 * @property int $id
 * @property int $inbound_message_id
 * @property int $conversation_id
 * @property int|null $outbound_message_id
 * @property AiProcessingStatus $status
 * @property string|null $skip_reason
 * @property string|null $failure_reason
 * @property Carbon|null $completed_at
 */
class AiMessageProcessing extends Model
{
    protected $fillable = [
        'inbound_message_id',
        'conversation_id',
        'outbound_message_id',
        'status',
        'skip_reason',
        'failure_reason',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AiProcessingStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'inbound_message_id');
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function outboundMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'outbound_message_id');
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
