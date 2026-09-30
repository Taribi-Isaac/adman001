<?php

namespace App\Support;

use App\Enums\WhatsAppTemplateKey;

/**
 * An approved Meta Utility template ADMAN may use for a transactional send outside the
 * 24-hour customer service window. Built from config only; null means "not available".
 */
final class WhatsAppTransactionalTemplate
{
    private function __construct(
        public readonly WhatsAppTemplateKey $key,
        public readonly string $name,
        public readonly string $language,
    ) {}

    public static function forKey(WhatsAppTemplateKey $key): ?self
    {
        $config = config('adman.whatsapp.templates.'.$key->configKey());

        if (! is_array($config) || ! (bool) ($config['enabled'] ?? false)) {
            return null;
        }

        $name = trim((string) ($config['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $language = trim((string) ($config['language'] ?? ''));
        if ($language === '') {
            $language = trim((string) config('adman.whatsapp.template_language', 'en')) ?: 'en';
        }

        return new self($key, $name, $language);
    }

    public static function unavailableReason(WhatsAppTemplateKey $key): string
    {
        return sprintf(
            'The customer has not messaged in the last 24 hours, so WhatsApp only allows an approved template, '
            .'and no approved %s template is enabled in ADMAN yet. Nothing was sent. Use email, or ask the customer to message the business first.',
            strtolower($key->label()),
        );
    }
}
