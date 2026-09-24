<?php

namespace Tests\Unit;

use App\Enums\DiscountType;
use App\Support\DocumentCalculator;
use App\Support\Money;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DocumentCalculatorTest extends TestCase
{
    public function test_quantity_times_unit_price(): void
    {
        $result = DocumentCalculator::calculate(
            lines: [
                ['quantity' => '2', 'unit_price' => '50.00'],
                ['quantity' => '1', 'unit_price' => '25.50'],
            ],
            discountType: DiscountType::None,
            discountValue: '0',
            taxEnabled: false,
            taxRate: '0',
        );

        $this->assertSame('100.00', $result['lines'][0]['line_subtotal']);
        $this->assertSame('25.50', $result['lines'][1]['line_subtotal']);
        $this->assertSame('125.50', $result['subtotal']);
        $this->assertSame('125.50', $result['total']);
    }

    public function test_percentage_discount_before_tax(): void
    {
        $result = DocumentCalculator::calculate(
            lines: [['quantity' => '1', 'unit_price' => '100.00']],
            discountType: DiscountType::Percentage,
            discountValue: '10',
            taxEnabled: true,
            taxRate: '7.5',
        );

        $this->assertSame('10.00', $result['discount_amount']);
        $this->assertSame('90.00', $result['taxable_subtotal']);
        $this->assertSame('6.75', $result['tax_amount']);
        $this->assertSame('96.75', $result['total']);
    }

    public function test_fixed_discount_before_tax(): void
    {
        $result = DocumentCalculator::calculate(
            lines: [['quantity' => '1', 'unit_price' => '200.00']],
            discountType: DiscountType::Fixed,
            discountValue: '50',
            taxEnabled: true,
            taxRate: '10',
        );

        $this->assertSame('50.00', $result['discount_amount']);
        $this->assertSame('150.00', $result['taxable_subtotal']);
        $this->assertSame('15.00', $result['tax_amount']);
        $this->assertSame('165.00', $result['total']);
    }

    public function test_discount_cannot_exceed_subtotal(): void
    {
        $this->expectException(ValidationException::class);

        DocumentCalculator::calculate(
            lines: [['quantity' => '1', 'unit_price' => '50.00']],
            discountType: DiscountType::Fixed,
            discountValue: '60',
            taxEnabled: false,
            taxRate: '0',
        );
    }

    public function test_money_rounding_is_deterministic(): void
    {
        $this->assertSame('0.33', Money::percentOf('1.00', '33.3333'));
        $this->assertSame('10.00', Money::mul('2.50', '4.00'));
        $this->assertSame('7.50', Money::sub('10.00', '2.50'));
    }

    public function test_rejects_empty_lines(): void
    {
        $this->expectException(ValidationException::class);

        DocumentCalculator::calculate(
            lines: [],
            discountType: DiscountType::None,
            discountValue: '0',
            taxEnabled: false,
            taxRate: '0',
        );
    }
}
