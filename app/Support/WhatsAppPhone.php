<?php

namespace App\Support;

use App\Models\Contact;

/**
 * WhatsApp Cloud API identifiers are digits-only phone numbers (country code, no +).
 *
 * ADMAN stores CommunicationIdentity.external_id in this form.
 * Prefer Contact.whatsapp_id when set; otherwise normalize Contact.phone.
 * No full international parsing library — operators must store numbers with country code.
 */
final class WhatsAppPhone
{
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
