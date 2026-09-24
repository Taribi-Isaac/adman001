<?php

namespace App\Ai\Tools;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Message;

final class GetBusinessInformationTool implements AiTool
{
    public function name(): string
    {
        return 'get_business_information';
    }

    public function description(): string
    {
        return 'Get public business identity, contact details, payment instructions, and terms. Safe for any sender.';
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
        $business = Business::current();

        return [
            'ok' => true,
            'business' => [
                'name' => $business->name,
                'legal_name' => $business->legal_name,
                'email' => $business->email,
                'phone' => $business->phone,
                'website' => $business->website,
                'address' => array_filter([
                    $business->address_line_1,
                    $business->address_line_2,
                    $business->city,
                    $business->state,
                    $business->postal_code,
                    $business->country,
                ]),
                'currency_code' => $business->currency_code,
                'payment_instructions' => $business->payment_instructions,
                'default_terms' => $business->default_terms,
                'bank' => array_filter([
                    'bank_name' => $business->bank_name,
                    'account_name' => $business->bank_account_name,
                    'account_number' => $business->bank_account_number,
                ]),
            ],
        ];
    }
}
