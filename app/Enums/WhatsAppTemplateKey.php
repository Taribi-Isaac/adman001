<?php

namespace App\Enums;

enum WhatsAppTemplateKey: string
{
    case Quote = 'quote';

    case Invoice = 'invoice';

    case InvoiceReminder = 'invoice_reminder';

    case PaymentAcknowledgement = 'payment_acknowledgement';

    public function label(): string
    {
        return match ($this) {
            self::Quote => 'Quote',
            self::Invoice => 'Invoice',
            self::InvoiceReminder => 'Invoice reminder',
            self::PaymentAcknowledgement => 'Payment acknowledgement',
        };
    }

    public function configKey(): string
    {
        return $this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
