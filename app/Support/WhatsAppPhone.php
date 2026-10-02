<?php

namespace App\Support;

use App\Models\Contact;

/**
 * WhatsApp Cloud API identifiers are digits-only phone numbers (country code, no +).
 *
 * ADMAN stores CommunicationIdentity.external_id in this form.
 * Prefer Contact.whatsapp_id when set; otherwise normalize Contact.phone.
 *
 * ADMAN operates in Nigeria, so Nigerian mobile numbers written in local form
 * (07054998090) or with the trunk zero kept after +234 (+234 0705…) are converted to
 * the canonical 2347054998090. Every other number must already include its country
 * code; there is no full international parsing library.
 */
final class WhatsAppPhone
{
    public const NIGERIA_COUNTRY_CODE = '234';

    /** Nigerian mobile number in local form: trunk 0 + 070x/080x/081x/090x/091x + 7 digits. */
    private const NIGERIAN_LOCAL_MOBILE = '/^0[789][01]\d{8}$/';

    /** +234 followed by the local trunk 0 (a common way of writing Nigerian numbers). */
    private const NIGERIAN_TRUNK_AFTER_CODE = '/^2340[789][01]\d{8}$/';

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        // Drop a single leading 00 international prefix if present.
        if (str_starts_with($digits, '00') && strlen($digits) > 8) {
            $digits = substr($digits, 2);
        }

        if (preg_match(self::NIGERIAN_LOCAL_MOBILE, $digits) === 1) {
            $digits = self::NIGERIA_COUNTRY_CODE.substr($digits, 1);
        } elseif (preg_match(self::NIGERIAN_TRUNK_AFTER_CODE, $digits) === 1) {
            $digits = self::NIGERIA_COUNTRY_CODE.substr($digits, 4);
        }

        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }

    public static function fromContact(Contact $contact): ?string
    {
        $fromWhatsAppId = self::normalize($contact->whatsapp_id);
        if ($fromWhatsAppId !== null) {
            return $fromWhatsAppId;
        }

        return self::normalize($contact->phone);
    }
}
