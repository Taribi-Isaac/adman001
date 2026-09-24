<?php

namespace Database\Factories;

use App\Models\RecurringBillingItem;
use App\Models\RecurringBillingSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringBillingItem>
 */
class RecurringBillingItemFactory extends Factory
{
    protected $model = RecurringBillingItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'schedule_id' => RecurringBillingSchedule::factory(),
            'position' => 0,
            'description' => fake()->sentence(3),
            'quantity' => '1.0000',
            'unit' => null,
            'unit_price' => '100.00',
        ];
    }
}
