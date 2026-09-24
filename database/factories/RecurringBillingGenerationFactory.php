<?php

namespace Database\Factories;

use App\Enums\RecurringBillingGenerationStatus;
use App\Models\RecurringBillingGeneration;
use App\Models\RecurringBillingSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringBillingGeneration>
 */
class RecurringBillingGenerationFactory extends Factory
{
    protected $model = RecurringBillingGeneration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->startOfMonth();

        return [
            'schedule_id' => RecurringBillingSchedule::factory(),
            'period_key' => $start->format('Y-m'),
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'status' => RecurringBillingGenerationStatus::Pending,
            'invoice_id' => null,
            'trigger' => 'scheduler',
            'attempted_at' => null,
            'completed_at' => null,
            'failure_reason' => null,
            'triggered_by' => null,
        ];
    }
}
