<?php

namespace App\WhatsApp;

/**
 * Staff-facing wording for WhatsApp Cloud API errors (send responses and status webhooks).
 * Never includes raw provider payloads or credentials.
 */
final class WhatsAppErrorMapper
{
    /** Codes that are worth retrying later (rate limits). */
    private const RETRYABLE_CODES = ['130429', '131056'];

    public static function reasonForCode(?string $code): ?string
    {
        return match ($code) {
            '131047', '470' => 'WhatsApp 24-hour customer service window has closed. WhatsApp requires an approved template message until the customer messages again.',
            '132001' => 'WhatsApp template does not exist in Meta for this name and language.',
            '132000', '132012' => 'WhatsApp template parameters do not match the approved template.',
            '132005', '132007' => 'WhatsApp rejected the template content (a value is too long or contains unsupported characters).',
            '132015' => 'WhatsApp template is paused by Meta (quality issue). Review it in WhatsApp Manager.',
            '132016' => 'WhatsApp template has been disabled by Meta.',
            '131050' => 'The customer has stopped receiving these messages on WhatsApp.',
            '131026' => 'Message undeliverable: the number may not be on WhatsApp or has not accepted the latest WhatsApp terms.',
            '131049' => 'Meta chose not to deliver this message (per-customer message limits). Try later or use email.',
            '131042' => 'WhatsApp account payment issue. Check the payment method in Meta Business settings.',
            '131031', '368' => 'WhatsApp Business account is locked or restricted by Meta.',
            '131048' => 'WhatsApp restricted sending from this number because of quality/spam limits.',
            '130429', '131056' => 'WhatsApp rate limit reached. Please retry later.',
            '190' => 'WhatsApp authentication failed. Check server credentials.',
            default => null,
        };
    }

    public static function reason(int $status, ?string $code): string
    {
        return self::reasonForCode($code) ?? match (true) {
            $status === 401, $status === 403 => 'WhatsApp authentication failed. Check server credentials.',
            $status === 404 => 'WhatsApp phone number configuration looks invalid.',
            $status === 429 => 'WhatsApp rate limit reached. Please retry later.',
            $status >= 400 && $status < 500 => 'WhatsApp rejected the message. Check recipient and template configuration.',
            default => 'WhatsApp provider rejected or failed the send. Please retry later.',
        };
    }

    public static function retryable(int $status, ?string $code): bool
    {
        if ($code !== null && in_array($code, self::RETRYABLE_CODES, true)) {
            return true;
        }

        if (in_array($status, [400, 401, 403, 404], true)) {
            return false;
        }

        return $status === 429 || $status >= 500;
    }
}
