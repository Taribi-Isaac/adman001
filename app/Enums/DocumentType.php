<?php

namespace App\Enums;

enum DocumentType: string
{
    case QuotePdf = 'quote_pdf';
    case InvoicePdf = 'invoice_pdf';
    case PaymentAcknowledgementPdf = 'payment_acknowledgement_pdf';

    public function label(): string
    {
        return match ($this) {
            self::QuotePdf => 'Quote PDF',
            self::InvoicePdf => 'Invoice PDF',
            self::PaymentAcknowledgementPdf => 'Payment acknowledgement',
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
