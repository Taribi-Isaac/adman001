<?php

namespace App\Enums;

/**
 * Payment state for invoices.
 *
 * Until the Payments domain exists, issued invoices remain Unpaid with
 * amount_paid = 0. Partially Paid / Paid will be driven by confirmed payments later.
 * "Outstanding" is a derived UI concept (Unpaid or Partially Paid with balance > 0).
 */
enum InvoicePaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
        };
    }

    public function isOutstanding(): bool
    {
        return $this === self::Unpaid || $this === self::PartiallyPaid;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
