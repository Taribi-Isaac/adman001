<?php

namespace App\Models;

use App\Enums\CommunicationChannel;
use App\Enums\ConversationMode;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Business communication thread.
 *
 * @property int $id
 * @property int $communication_identity_id
 * @property int|null $contact_id
 * @property CommunicationChannel $channel
 * @property ConversationMode $mode
 * @property int|null $assigned_user_id
 * @property string|null $subject
 * @property Carbon|null $last_message_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected $fillable = [
        'communication_identity_id',
        'contact_id',
        'channel',
        'mode',
        'assigned_user_id',
        'subject',
        'last_message_at',
        'closed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => CommunicationChannel::class,
            'mode' => ConversationMode::class,
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return $this->mode === ConversationMode::Closed || $this->closed_at !== null;
    }

    public function isHumanControlled(): bool
    {
        return $this->mode === ConversationMode::Human;
    }

    /**
     * @return BelongsTo<CommunicationIdentity, $this>
     */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(CommunicationIdentity::class, 'communication_identity_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
