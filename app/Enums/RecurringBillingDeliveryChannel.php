<?php

namespace App\Enums;

enum RecurringBillingDeliveryChannel: string
{
    case None = 'none';
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
            self::Both => 'Email + WhatsApp',
        };
    }

    /**
     * @return list<CommunicationChannel>
     */
    public function channels(): array
    {
        return match ($this) {
            self::None => [],
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
