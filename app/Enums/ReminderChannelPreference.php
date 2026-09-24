<?php

namespace App\Enums;

enum ReminderChannelPreference: string
{
    case Email = 'email';

    case WhatsApp = 'whatsapp';

    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
            self::Both => 'Both',
        };
    }

    /**
     * @return list<CommunicationChannel>
     */
    public function channels(): array
    {
        return match ($this) {
            self::Email => [CommunicationChannel::Email],
            self::WhatsApp => [CommunicationChannel::WhatsApp],
            self::Both => [CommunicationChannel::Email, CommunicationChannel::WhatsApp],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
