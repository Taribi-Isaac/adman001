<?php

namespace Database\Factories;

use App\Models\Quote;
use App\Models\QuoteItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteItem>
 */
class QuoteItemFactory extends Factory
{
    protected $model = QuoteItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = '1.0000';
        $unitPrice = '100.00';

        return [
            'quote_id' => Quote::factory(),
            'position' => 0,
            'description' => fake()->sentence(3),
            'quantity' => $quantity,
            'unit' => null,
            'unit_price' => $unitPrice,
            'line_subtotal' => $unitPrice,
        ];
    }
}
