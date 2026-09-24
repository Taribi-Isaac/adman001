<?php

namespace App\Enums;

enum EmailTemplateKey: string
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

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
