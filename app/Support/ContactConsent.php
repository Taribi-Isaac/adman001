<?php

namespace App\Support;

use App\Enums\CommunicationChannel;
use App\Enums\ConsentSource;
use App\Models\Contact;
use Illuminate\Validation\Rule;

/**
 * Consent flags staff can record on a contact.
 *
 * `whatsapp_opt_in` keeps its boolean column (used by transactional sends); the other
 * flags are "on" when their timestamp is set. A source is recorded evidence of how
 * consent was captured, not proof of legal consent.
 */
final class ContactConsent
{
    /**
     * @var array<string, array{channel: CommunicationChannel, at: string, source: string, label: string}>
     */
    public const FLAGS = [
        'whatsapp_opt_in' => [
            'channel' => CommunicationChannel::WhatsApp,
            'at' => 'whatsapp_opt_in_at',
            'source' => 'whatsapp_opt_in_source',
            'label' => 'WhatsApp opt-in',
        ],
        'whatsapp_broadcast_opt_out' => [
            'channel' => CommunicationChannel::WhatsApp,
            'at' => 'whatsapp_broadcast_opt_out_at',
            'source' => 'whatsapp_broadcast_opt_out_source',
            'label' => 'WhatsApp broadcast opt-out',
        ],
        'email_broadcast_opt_in' => [
            'channel' => CommunicationChannel::Email,
            'at' => 'email_broadcast_opt_in_at',
            'source' => 'email_broadcast_opt_in_source',
            'label' => 'Email broadcast opt-in',
        ],
        'email_broadcast_unsubscribed' => [
            'channel' => CommunicationChannel::Email,
            'at' => 'email_broadcast_unsubscribed_at',
            'source' => 'email_broadcast_unsubscribe_source',
            'label' => 'Email broadcast unsubscribe',
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach (self::FLAGS as $flag => $definition) {
            $rules[$flag] = ['sometimes', 'boolean'];
            $rules[$definition['source']] = ['nullable', Rule::enum(ConsentSource::class)];
        }

        return $rules;
    }

    public static function isOn(Contact $contact, string $flag): bool
    {
        if ($flag === 'whatsapp_opt_in') {
            return (bool) $contact->whatsapp_opt_in;
        }

        return $contact->getAttribute(self::FLAGS[$flag]['at']) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(Contact $contact): array
    {
        $payload = [];

        foreach (self::FLAGS as $flag => $definition) {
            $source = $contact->getAttribute($definition['source']);

            $payload[$flag] = self::isOn($contact, $flag);
            $payload[$definition['at']] = $contact->getAttribute($definition['at'])?->toIso8601String();
            $payload[$definition['source']] = $source instanceof ConsentSource ? $source->value : null;
        }

        return $payload;
    }
}
