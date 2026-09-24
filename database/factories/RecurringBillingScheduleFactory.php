<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Enums\RecurringBillingFrequency;
use App\Enums\RecurringBillingStatus;
use App\Models\Business;
use App\Models\Contact;
use App\Models\RecurringBillingSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringBillingSchedule>
 */
class RecurringBillingScheduleFactory extends Factory
{
    protected $model = RecurringBillingSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->startOfDay();

        return [
            'business_id' => fn () => Business::current()->id,
            'contact_id' => Contact::factory()->customer(),
            'frequency' => RecurringBillingFrequency::Monthly,
            'start_date' => $start->toDateString(),
            'end_date' => null,
            'next_generation_date' => $start->toDateString(),
            'status' => RecurringBillingStatus::Active,
            'payment_term_days' => 14,
            'currency_code' => 'NGN',
            'discount_type' => DiscountType::None,
            'discount_value' => '0.0000',
            'tax_enabled' => false,
            'tax_rate' => '0.0000',
            'notes' => null,
            'terms' => null,
            'paused_at' => null,
            'cancelled_at' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (RecurringBillingSchedule $schedule): void {
            if ($schedule->items()->exists()) {
                return;
            }

            $schedule->items()->create([
                'position' => 0,
                'description' => 'Monthly retainer',
                'quantity' => '1.0000',
                'unit' => 'mo',
                'unit_price' => '100.00',
            ]);
        });
    }

    public function paused(): static
    {
        return $this->state(fn () => [
            'status' => RecurringBillingStatus::Paused,
            'paused_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => RecurringBillingStatus::Cancelled,
            'cancelled_at' => now(),
            'next_generation_date' => null,
        ]);
    }
}
