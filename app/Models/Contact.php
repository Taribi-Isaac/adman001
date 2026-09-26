<?php

namespace App\Models;

use App\Enums\ContactStatus;
use App\Enums\ContactType;
use App\Enums\ReminderChannelPreference;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Canonical contact/customer identity for ADMAN.
 *
 * Lifecycle: Unknown → Prospect → Customer
 *
 * @property int $id
 * @property ContactType $type
 * @property ContactStatus $status
 * @property string $display_name
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $organization_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $whatsapp_id
 * @property bool $whatsapp_opt_in
 * @property string $reminder_channel
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postal_code
 * @property string|null $country
 * @property string|null $notes
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    protected $fillable = [
        'type',
        'status',
        'display_name',
        'first_name',
        'last_name',
        'organization_name',
        'email',
        'phone',
        'whatsapp_id',
        'whatsapp_opt_in',
        'reminder_channel',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'notes',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
            'status' => ContactStatus::class,
            'whatsapp_opt_in' => 'boolean',
            'reminder_channel' => ReminderChannelPreference::class,
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isCustomer(): bool
    {
        return $this->status === ContactStatus::Customer;
    }

    /**
     * @return HasMany<CommunicationIdentity, $this>
     */
    public function communicationIdentities(): HasMany
    {
        return $this->hasMany(CommunicationIdentity::class);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return HasMany<Quote, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * Derive a stable display name from available identity fields.
     */
    public static function deriveDisplayName(
        ContactType $type,
        ?string $firstName,
        ?string $lastName,
        ?string $organizationName,
        ?string $email,
        ?string $phone,
        ?string $whatsappId,
    ): string {
        if ($type === ContactType::Organization) {
            $name = trim((string) $organizationName);
            if ($name !== '') {
                return $name;
            }
        }

        $person = trim(implode(' ', array_filter([
            trim((string) $firstName),
            trim((string) $lastName),
        ])));

        if ($person !== '') {
            return $person;
        }

        if ($type === ContactType::Individual && trim((string) $organizationName) !== '') {
            return trim((string) $organizationName);
        }

        foreach ([$email, $phone, $whatsappId] as $fallback) {
            $value = trim((string) $fallback);
            if ($value !== '') {
                return $value;
            }
        }

        return 'Unknown contact';
    }
}
