<?php

namespace App\Ai\Tools;

use App\Enums\QuoteStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Quote;
use App\Services\Ai\AiAuthorization;

final class GetCustomerQuotesTool implements AiTool
{
    public function __construct(private readonly AiAuthorization $auth) {}

    public function name(): string
    {
        return 'get_customer_quotes';
    }

    public function description(): string
    {
        return 'List quotes for the authorized customer.';
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
            return ['ok' => false, 'error' => 'Customer-specific quote data is not available for this sender.'];
        }

        $quotes = Quote::query()
            ->where('contact_id', $contact->id)
            ->where('status', '!=', QuoteStatus::Draft->value)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return [
            'ok' => true,
            'quotes' => $quotes->map(fn (Quote $quote) => [
                'number' => $quote->number,
                'status' => $quote->status->value,
                'status_label' => $quote->status->label(),
                'total' => (string) $quote->total,
                'currency_code' => $quote->currency_code,
                'expiry_date' => $quote->expiry_date?->toDateString(),
            ])->all(),
        ];
    }
}
