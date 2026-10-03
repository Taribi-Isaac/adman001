<?php

namespace App\Models;

use App\Enums\BroadcastAudience;
use App\Enums\BroadcastStatus;
use App\Enums\CommunicationChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A one-off marketing broadcast on one channel (Task 038).
 *
 * The recipient snapshot is created when sending starts; draft broadcasts have none.
 *
 * @property int $id
 * @property string $name
 * @property CommunicationChannel $channel
 * @property BroadcastAudience $audience_type
 * @property list<int>|null $selected_contact_ids
 * @property string|null $subject
 * @property string|null $body
 * @property string|null $whatsapp_template_name
 * @property string|null $whatsapp_template_language
 * @property string|null $whatsapp_message
 * @property BroadcastStatus $status
 * @property int|null $recipient_limit
 * @property int $recipient_count
 * @property array<string, int>|null $exclusion_summary
 * @property string|null $failure_reason
 * @property int $created_by
 * @property int|null $sent_by
 * @property int|null $cancelled_by
 * @property Carbon|null $send_requested_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Broadcast extends Model
{
    protected $fillable = [
        'name',
        'channel',
        'audience_type',
        'selected_contact_ids',
        'subject',
        'body',
        'whatsapp_template_name',
        'whatsapp_template_language',
        'whatsapp_message',
        'status',
        'recipient_limit',
        'recipient_count',
        'exclusion_summary',
        'failure_reason',
        'created_by',
        'sent_by',
        'cancelled_by',
        'send_requested_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'failed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => CommunicationChannel::class,
            'audience_type' => BroadcastAudience::class,
            'selected_contact_ids' => 'array',
            'status' => BroadcastStatus::class,
            'recipient_limit' => 'integer',
            'recipient_count' => 'integer',
            'exclusion_summary' => 'array',
            'send_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<BroadcastRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isDraft(): bool
    {
        return $this->status === BroadcastStatus::Draft;
    }
}
