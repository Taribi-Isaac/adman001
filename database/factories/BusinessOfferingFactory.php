<?php

namespace Database\Factories;

use App\Models\BusinessOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessOffering>
 */
class BusinessOfferingFactory extends Factory
{
    protected $model = BusinessOffering::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
