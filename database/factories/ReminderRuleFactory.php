<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\ReminderRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReminderRule>
 */
class ReminderRuleFactory extends Factory
{
    protected $model = ReminderRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::current()->id,
            'offset_days' => -7,
            'is_enabled' => true,
            'sort_order' => 1,
        ];
    }
}
