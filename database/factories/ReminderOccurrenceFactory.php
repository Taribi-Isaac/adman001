<?php

namespace Database\Factories;

use App\Enums\CommunicationChannel;
use App\Enums\ReminderOccurrenceStatus;
use App\Models\Invoice;
use App\Models\ReminderOccurrence;
use App\Models\ReminderRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReminderOccurrence>
 */
class ReminderOccurrenceFactory extends Factory
{
    protected $model = ReminderOccurrence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'reminder_rule_id' => ReminderRule::factory(),
            'channel' => CommunicationChannel::Email,
            'occurrence_date' => now()->toDateString(),
            'status' => ReminderOccurrenceStatus::Pending,
        ];
    }
}
