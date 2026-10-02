<?php

namespace App\Models;

use App\Enums\CommunicationChannel;
use App\Support\WhatsAppPhone;
use Database\Factories\CommunicationIdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * External channel identity (WhatsApp ID, email address, etc.).
 *
 * May exist without a linked Contact.
 *
 * @property int $id
 * @property CommunicationChannel $channel
 * @property string $external_id
 * @property string|null $display_name
 * @property int|null $contact_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CommunicationIdentity extends Model
{
    /** @use HasFactory<CommunicationIdentityFactory> */
    use HasFactory;

    protected $fillable = [
        'channel',
        'external_id',
        'display_name',
        'contact_id',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => CommunicationChannel::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * New WhatsApp identities are always stored in canonical form, so the unique
     * (channel, external_id) index also blocks equivalent spellings of one number.
     * Only applied on create: legacy non-canonical rows are left as they are.
     */
    protected static function booted(): void
    {
        static::creating(function (CommunicationIdentity $identity): void {
            if ($identity->channel !== CommunicationChannel::WhatsApp) {
                return;
            }

            $canonical = WhatsAppPhone::normalize($identity->external_id);
            if ($canonical !== null) {
                $identity->external_id = $canonical;
            }
        });
    }

    public function isLinked(): bool
    {
        return $this->contact_id !== null;
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function label(): string
    {
        if (filled($this->display_name)) {
            return (string) $this->display_name;
        }

        return $this->external_id;
    }
}
