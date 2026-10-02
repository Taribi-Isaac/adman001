<?php

namespace App\Enums;

/**
 * How staff recorded a consent or opt-out. Evidence of the recording method only;
 * it does not by itself prove legal consent.
 */
enum ConsentSource: string
{
    case InPerson = 'in_person';
    case Website = 'website';
    case WhatsApp = 'whatsapp';
    case Phone = 'phone';
    case StaffRecorded = 'staff_recorded';
    case Other = 'other';

    /** Set by the system when a recipient uses the unsubscribe link in a broadcast email. */
    case UnsubscribeLink = 'unsubscribe_link';

    public function label(): string
    {
        return match ($this) {
            self::InPerson => 'In person',
            self::Website => 'Website',
            self::WhatsApp => 'WhatsApp',
            self::Phone => 'Phone',
            self::StaffRecorded => 'Recorded by staff',
            self::Other => 'Other',
            self::UnsubscribeLink => 'Email unsubscribe link',
        };
    }

    /**
     * Sources staff may choose when recording consent by hand.
     *
     * @return list<self>
     */
    public static function staffSelectable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $source) => $source !== self::UnsubscribeLink));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
