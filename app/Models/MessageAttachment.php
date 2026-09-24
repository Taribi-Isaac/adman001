<?php

namespace App\Models;

use App\Enums\AttachmentReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Private inbound media/file attached to a WhatsApp (or other) message.
 *
 * @property int $id
 * @property int $message_id
 * @property int $conversation_id
 * @property int|null $contact_id
 * @property string|null $provider_media_id
 * @property string|null $original_filename
 * @property string|null $mime_type
 * @property int|null $byte_size
 * @property string $disk
 * @property string $path
 * @property string|null $media_kind
 * @property string $processing_status
 * @property AttachmentReviewStatus $review_status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $reviewer_notes
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $meta
 */
class MessageAttachment extends Model
{
    protected $fillable = [
        'message_id',
        'conversation_id',
        'contact_id',
        'provider_media_id',
        'original_filename',
        'mime_type',
        'byte_size',
        'disk',
        'path',
        'media_kind',
        'processing_status',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'reviewer_notes',
        'failure_reason',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'review_status' => AttachmentReviewStatus::class,
            'reviewed_at' => 'datetime',
            'meta' => 'array',
            'byte_size' => 'integer',
        ];
    }

    public function existsOnDisk(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
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
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
