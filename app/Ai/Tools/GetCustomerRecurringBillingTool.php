<?php

namespace App\Ai\Tools;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\RecurringBillingSchedule;
use App\Services\Ai\AiAuthorization;

final class GetCustomerRecurringBillingTool implements AiTool
{
    public function __construct(private readonly AiAuthorization $auth) {}

    public function name(): string
    {
        return 'get_customer_recurring_billing';
    }

    public function description(): string
    {
        return 'List recurring billing schedules for the authorized customer (read-only).';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
            'additionalProperties' => false,
        ];
    }

    public function execute(Conversation $conversation, Message $inbound, array $arguments): array
    {
        $contact = $this->auth->authorizedContact($conversation);
        if ($contact === null) {
            return ['ok' => false, 'error' => 'Recurring billing data is not available for this sender.'];
        }

        $schedules = RecurringBillingSchedule::query()
            ->where('contact_id', $contact->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return [
            'ok' => true,
            'schedules' => $schedules->map(fn (RecurringBillingSchedule $schedule) => [
                'status' => $schedule->status->value,
                'status_label' => $schedule->status->label(),
                'frequency' => $schedule->frequency->value,
                'frequency_label' => $schedule->frequency->label(),
                'next_generation_date' => $schedule->next_generation_date?->toDateString(),
                'currency_code' => $schedule->currency_code,
                'payment_term_days' => $schedule->payment_term_days,
            ])->all(),
        ];
    }
}
