<?php

namespace App\Support;

use App\Enums\DiscountType;
use Illuminate\Validation\ValidationException;

/**
 * Locked commercial calculation order:
 * 1) line subtotal = qty × unit price
 * 2) document discount (percent or fixed)
 * 3) taxable = subtotal − discount
 * 4) tax on taxable
 * 5) grand total = taxable + tax
 */
final class DocumentCalculator
{
    /**
     * @param  list<array{quantity: string|int|float, unit_price: string|int|float}>  $lines
     * @return array{
     *     lines: list<array{quantity: string, unit_price: string, line_subtotal: string}>,
     *     subtotal: string,
     *     discount_type: string,
     *     discount_value: string,
     *     discount_amount: string,
     *     taxable_subtotal: string,
     *     tax_enabled: bool,
     *     tax_rate: string,
     *     tax_amount: string,
     *     total: string
     * }
     */
    public static function calculate(
        array $lines,
        DiscountType $discountType,
        string|int|float $discountValue,
        bool $taxEnabled,
        string|int|float|null $taxRate,
    ): array {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'items' => 'At least one line item is required.',
            ]);
        }

        $computedLines = [];
        $subtotal = '0.00';

        foreach ($lines as $index => $line) {
            $quantity = Money::normalize((string) $line['quantity'], 4);
            $unitPrice = Money::normalize((string) $line['unit_price']);

            if (Money::compare($quantity, '0', 4) <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => 'Quantity must be greater than zero.',
                ]);
            }

            if (Money::isNegative($unitPrice)) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_price" => 'Unit price cannot be negative.',
                ]);
            }

            $lineSubtotal = bcmul($quantity, $unitPrice, Money::SCALE);
            $computedLines[] = [
                'quantity' => Money::normalize($quantity, 4),
                'unit_price' => $unitPrice,
                'line_subtotal' => $lineSubtotal,
            ];
            $subtotal = Money::add($subtotal, $lineSubtotal);
        }

        $discountValue = Money::normalize((string) $discountValue, $discountType === DiscountType::Percentage ? Money::RATE_SCALE : Money::SCALE);

        if (Money::isNegative($discountValue, $discountType === DiscountType::Percentage ? Money::RATE_SCALE : Money::SCALE)) {
            throw ValidationException::withMessages([
                'discount_value' => 'Discount cannot be negative.',
            ]);
        }

        $discountAmount = match ($discountType) {
            DiscountType::None => '0.00',
            DiscountType::Fixed => Money::normalize($discountValue),
            DiscountType::Percentage => Money::percentOf($subtotal, $discountValue),
        };

        if (Money::compare($discountAmount, $subtotal) > 0) {
            throw ValidationException::withMessages([
                'discount_value' => 'Discount cannot exceed the subtotal.',
            ]);
        }

        $taxable = Money::sub($subtotal, $discountAmount);
        if (Money::isNegative($taxable)) {
            throw ValidationException::withMessages([
                'discount_value' => 'Discount would produce a negative taxable amount.',
            ]);
        }

        $rate = $taxEnabled ? Money::normalize((string) ($taxRate ?? '0'), Money::RATE_SCALE) : '0.0000';
        if (Money::isNegative($rate, Money::RATE_SCALE)) {
            throw ValidationException::withMessages([
                'tax_rate' => 'Tax rate cannot be negative.',
            ]);
        }

        $taxAmount = ($taxEnabled && Money::compare($rate, '0', Money::RATE_SCALE) > 0)
            ? Money::percentOf($taxable, $rate)
            : '0.00';

        $total = Money::add($taxable, $taxAmount);

        return [
            'lines' => $computedLines,
            'subtotal' => $subtotal,
            'discount_type' => $discountType->value,
            'discount_value' => $discountType === DiscountType::None
                ? '0.00'
                : ($discountType === DiscountType::Percentage
                    ? Money::normalize($discountValue, Money::RATE_SCALE)
                    : Money::normalize($discountValue)),
            'discount_amount' => $discountAmount,
            'taxable_subtotal' => $taxable,
            'tax_enabled' => $taxEnabled,
            'tax_rate' => $rate,
            'tax_amount' => $taxAmount,
            'total' => $total,
        ];
    }
}
