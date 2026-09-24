<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = '100.00';

        return [
            'invoice_id' => Invoice::factory(),
            'position' => 0,
            'description' => fake()->sentence(3),
            'quantity' => '1.0000',
            'unit' => null,
            'unit_price' => $unitPrice,
            'line_subtotal' => $unitPrice,
        ];
    }
}
