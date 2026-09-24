<?php

namespace App\Models;

use App\Enums\CommunicationChannel;
use App\Enums\MessageActorType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Message within a conversation.
 *
 * Status `recorded` means stored in ADMAN only — not externally delivered.
 * Pending / processing / sent / delivered / failed are used for provider delivery.
 *
 * @property int $id
 * @property int $conversation_id
 * @property MessageDirection $direction
 * @property CommunicationChannel $channel
 * @property string $body
 * @property string|null $subject
 * @property string|null $template_key
 * @property int|null $document_id
 * @property MessageStatus $status
 * @property MessageActorType $actor_type
 * @property int|null $actor_user_id
 * @property string|null $external_message_id
 * @property string|null $failure_reason
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property array<string, mixed>|null $meta
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'direction',
        'channel',
        'body',
        'subject',
        'template_key',
        'document_id',
        'status',
        'actor_type',
        'actor_user_id',
        'external_message_id',
        'failure_reason',
        'sent_at',
        'failed_at',
        'meta',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'channel' => CommunicationChannel::class,
            'status' => MessageStatus::class,
            'actor_type' => MessageActorType::class,
            'occurred_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
