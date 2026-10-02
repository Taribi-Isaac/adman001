<?php

namespace App\Models;

use App\Enums\BroadcastRecipientStatus;
use App\Enums\CommunicationChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One contact in a broadcast's recipient snapshot. The outbound itself is a normal
 * {@see Message} (communication history); this row only tracks broadcast progress.
 *
 * @property int $id
 * @property int $broadcast_id
 * @property int $contact_id
 * @property int|null $communication_identity_id
 * @property CommunicationChannel $channel
 * @property string|null $address
 * @property BroadcastRecipientStatus $status
 * @property int|null $message_id
 * @property string|null $provider_message_id
 * @property string|null $failure_reason
 * @property Carbon|null $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BroadcastRecipient extends Model
{
    protected $fillable = [
        'broadcast_id',
        'contact_id',
        'communication_identity_id',
        'channel',
        'address',
        'status',
        'message_id',
        'provider_message_id',
        'failure_reason',
        'queued_at',
        'sent_at',
        'delivered_at',
        'failed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => CommunicationChannel::class,
            'status' => BroadcastRecipientStatus::class,
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Broadcast, $this>
     */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
