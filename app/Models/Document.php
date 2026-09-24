<?php

namespace App\Models;

use App\Enums\DocumentType;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Generated commercial document (PDF) with optional secure access token.
 *
 * @property int $id
 * @property DocumentType $type
 * @property string $documentable_type
 * @property int $documentable_id
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property string $mime_type
 * @property int|null $byte_size
 * @property string|null $access_token_hash
 * @property Carbon|null $access_expires_at
 * @property Carbon|null $access_revoked_at
 * @property int|null $generated_by
 * @property Carbon $generated_at
 * @property array<string, mixed>|null $meta
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'type',
        'documentable_type',
        'documentable_id',
        'disk',
        'path',
        'filename',
        'mime_type',
        'byte_size',
        'access_token_hash',
        'access_expires_at',
        'access_revoked_at',
        'generated_by',
        'generated_at',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'access_expires_at' => 'datetime',
            'access_revoked_at' => 'datetime',
            'generated_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function isAccessActive(): bool
    {
        if ($this->access_token_hash === null || $this->access_revoked_at !== null) {
            return false;
        }

        if ($this->access_expires_at !== null && $this->access_expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function contents(): string
    {
        return Storage::disk($this->disk)->get($this->path);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
