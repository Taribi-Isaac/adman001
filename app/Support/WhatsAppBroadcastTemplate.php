<?php

namespace App\Support;

use App\Enums\WhatsAppTemplateKey;

/**
 * The approved Meta Marketing template used for WhatsApp broadcasts. Built from config only;
 * ADMAN cannot read the template category from Meta, so `enabled=true` is the operator's
 * statement that Meta shows this template as APPROVED / MARKETING.
 */
final class WhatsAppBroadcastTemplate
{
    /** Body variables ADMAN can fill, in the order configured. */
    public const SUPPORTED_PARAMETERS = ['contact_name'];

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

        $unsupported = array_diff(self::parseParameters($config['parameters'] ?? ''), self::SUPPORTED_PARAMETERS);
        if ($unsupported !== []) {
            return 'Unsupported WhatsApp broadcast template parameter: '.implode(', ', $unsupported)
                .'. Supported: '.implode(', ', self::SUPPORTED_PARAMETERS).' (or none).';
        }

        return null;
    }

    /**
     * Body parameter values for one recipient, in template order.
     *
     * @return list<string>
     */
    public function bodyParametersFor(string $contactName): array
    {
        return array_map(fn (string $parameter) => match ($parameter) {
            'contact_name' => $contactName,
        }, $this->parameters);
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
