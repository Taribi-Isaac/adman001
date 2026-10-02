<?php

namespace App\Enums;

enum EmailTemplateKey: string
{
    case Quote = 'quote';

    case Invoice = 'invoice';

    case InvoiceReminder = 'invoice_reminder';

    case PaymentAcknowledgement = 'payment_acknowledgement';

    /** Marketing broadcast email (Task 038): no attachment, always carries an unsubscribe link. */
    case Broadcast = 'broadcast';

    public function label(): string
    {
        return match ($this) {
            self::Quote => 'Quote',
            self::Invoice => 'Invoice',
            self::InvoiceReminder => 'Invoice reminder',
            self::PaymentAcknowledgement => 'Payment acknowledgement',
            self::Broadcast => 'Broadcast',
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
