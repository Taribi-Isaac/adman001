<?php

namespace App\Ai\Tools;

use App\Enums\ContactStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiAuthorization;

final class GetCustomerContextTool implements AiTool
{
    public function __construct(private readonly AiAuthorization $auth) {}

    public function name(): string
    {
        return 'get_customer_context';
    }

    public function description(): string
    {
        return 'Get the linked contact profile for this conversation when authorized. Denied for unknown senders.';
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
            return [
                'ok' => false,
                'error' => 'No authorized customer is linked to this conversation. Only general business information is available.',
            ];
        }

        return [
            'ok' => true,
            'customer' => [
                'display_name' => $contact->display_name,
                'status' => $contact->status->value,
                'status_label' => $contact->status->label(),
                'email' => $contact->email,
                'phone' => $contact->phone,
                'is_customer' => $contact->status === ContactStatus::Customer,
            ],
        ];
    }
}
