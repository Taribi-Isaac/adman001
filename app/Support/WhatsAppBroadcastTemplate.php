<?php

namespace App\Support;

use App\Enums\WhatsAppTemplateKey;
use App\Models\Broadcast;
use Illuminate\Validation\ValidationException;

/**
 * The approved Meta Marketing template used for WhatsApp broadcasts. Built from config only;
 * ADMAN cannot read the template category from Meta, so `enabled=true` is the operator's
 * statement that Meta shows this template as APPROVED / MARKETING.
 *
 * Body values are always built here from the persisted Broadcast and Contact; nothing from
 * the request reaches the template parameters.
 */
final class WhatsAppBroadcastTemplate
{
    /** Body variables ADMAN can fill, in the order configured. */
    public const SUPPORTED_PARAMETERS = ['contact_name', 'broadcast_message'];

    /** Meta limits a template body to 1024 characters; this leaves room for the fixed wording and the contact name. */
    public const MESSAGE_MAX_LENGTH = 700;

    /**
     * @param  list<string>  $parameters
     */
    private function __construct(
        public readonly string $name,
        public readonly string $language,
        public readonly array $parameters,
    ) {}

    public static function configured(): ?self
    {
        return self::problem() === null ? self::build() : null;
    }

    /**
     * Whether WhatsApp broadcasts currently need a campaign message (the configured template has a `broadcast_message` variable).
     */
    public static function configuredUsesMessage(): bool
    {
        return self::configured()?->usesMessage() ?? false;
    }

    /**
     * Why a WhatsApp broadcast cannot be started, or null when the template is usable.
     */
    public static function problem(): ?string
    {
        $config = config('adman.whatsapp.broadcast_template');
        $name = is_array($config) ? trim((string) ($config['name'] ?? '')) : '';

        if (! is_array($config) || ! (bool) ($config['enabled'] ?? false) || $name === '') {
            return 'No approved WhatsApp Marketing template is configured. Create a Marketing template in WhatsApp Manager, '
                .'wait until Meta shows it as Approved, then set WHATSAPP_TEMPLATE_BROADCAST and WHATSAPP_TEMPLATE_BROADCAST_ENABLED=true on the server.';
        }

        if (in_array(strtolower($name), self::transactionalTemplateNames(), true)) {
            return 'The configured broadcast template is one of the transactional Utility templates. '
                .'Broadcasts need a separate approved Marketing template.';
        }

        $parameters = self::parseParameters($config['parameters'] ?? '');

        $unsupported = array_diff($parameters, self::SUPPORTED_PARAMETERS);
        if ($unsupported !== []) {
            return 'Unsupported WhatsApp broadcast template parameter: '.implode(', ', $unsupported)
                .'. Supported: '.implode(', ', self::SUPPORTED_PARAMETERS).' (or none).';
        }

        if (count($parameters) !== count(array_unique($parameters))) {
            return 'Each WhatsApp broadcast template parameter can be listed only once.';
        }

        return null;
    }

    public function usesMessage(): bool
    {
        return in_array('broadcast_message', $this->parameters, true);
    }

    /**
     * Why this broadcast's content does not fit this template, or null when it does.
     */
    public function problemFor(Broadcast $broadcast): ?string
    {
        $message = trim((string) $broadcast->whatsapp_message);

        if ($this->usesMessage() && $message === '') {
            return 'The WhatsApp template needs a campaign message. Edit the draft and add the message.';
        }

        if (! $this->usesMessage() && $message !== '') {
            return 'The configured WhatsApp template has fixed wording and cannot include a campaign message. '
                .'Edit the draft and remove the message.';
        }

        if (mb_strlen($message) > self::MESSAGE_MAX_LENGTH) {
            return 'The campaign message is longer than '.self::MESSAGE_MAX_LENGTH.' characters.';
        }

        return null;
    }

    /**
     * Body parameter values for one recipient, in template order.
     *
     * @return list<string>
     *
     * @throws ValidationException when the template needs a campaign message and none is given
     */
    public function bodyParametersFor(string $contactName, ?string $broadcastMessage = null): array
    {
        $message = self::cleanParameter((string) $broadcastMessage);

        if ($this->usesMessage() && $message === '') {
            throw ValidationException::withMessages([
                'whatsapp' => 'The WhatsApp broadcast template needs a campaign message.',
            ]);
        }

        return array_map(fn (string $parameter) => match ($parameter) {
            'contact_name' => self::cleanParameter($contactName),
            'broadcast_message' => $message,
        }, $this->parameters);
    }

    /**
     * Meta rejects template variables containing line breaks, tabs or long runs of spaces,
     * so every value is collapsed to single spaces (the same rule applied right before delivery).
     */
    public static function cleanParameter(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Substitute {{1}}, {{2}}… in an approved template body with the given values.
     *
     * @param  list<string>  $values
     */
    public static function render(string $body, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*(\d+)\s*\}\}/',
            fn (array $m) => $values[(int) $m[1] - 1] ?? $m[0],
            $body,
        );
    }

    private static function build(): self
    {
        $config = (array) config('adman.whatsapp.broadcast_template');

        $language = trim((string) ($config['language'] ?? ''));
        if ($language === '') {
            $language = trim((string) config('adman.whatsapp.template_language', 'en')) ?: 'en';
        }

        return new self(
            trim((string) $config['name']),
            $language,
            self::parseParameters($config['parameters'] ?? ''),
        );
    }

    /**
     * @return list<string>
     */
    private static function parseParameters(mixed $value): array
    {
        $parts = array_map('trim', explode(',', strtolower((string) $value)));

        return array_values(array_filter($parts, fn (string $part) => $part !== ''));
    }

    /**
     * @return list<string>
     */
    private static function transactionalTemplateNames(): array
    {
        $names = [];

        foreach (WhatsAppTemplateKey::cases() as $key) {
            $name = strtolower(trim((string) config('adman.whatsapp.templates.'.$key->configKey().'.name', '')));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
