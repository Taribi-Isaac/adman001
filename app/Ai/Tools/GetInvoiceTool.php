<?php

namespace App\Ai\Tools;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiAuthorization;

final class GetInvoiceTool implements AiTool
{
    public function __construct(private readonly AiAuthorization $auth) {}

    public function name(): string
    {
        return 'get_invoice';
    }

    public function description(): string
    {
        return 'Get one invoice belonging to the authorized customer by invoice number.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'invoice_number' => [
                    'type' => 'string',
                    'description' => 'Invoice number such as INV-001',
                ],
            ],
            'required' => ['invoice_number'],
            'additionalProperties' => false,
        ];
    }

    public function execute(Conversation $conversation, Message $inbound, array $arguments): array
    {
        $number = trim((string) ($arguments['invoice_number'] ?? ''));
        if ($number === '') {
            return ['ok' => false, 'error' => 'invoice_number is required.'];
        }

        $invoice = $this->auth->authorizedInvoice($conversation, $number);
        if ($invoice === null) {
            return ['ok' => false, 'error' => 'Invoice not found for this customer, or access is not authorized.'];
        }

        return [
            'ok' => true,
            'invoice' => [
                'number' => $invoice->number,
                'lifecycle_status' => $invoice->lifecycle_status->value,
                'payment_status' => $invoice->payment_status->value,
                'payment_status_label' => $invoice->payment_status->label(),
                'total' => (string) $invoice->total,
                'amount_paid' => (string) $invoice->amount_paid,
                'balance_due' => (string) $invoice->balance_due,
                'currency_code' => $invoice->currency_code,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'due_state' => $invoice->dueState()?->value,
                'notes' => $invoice->notes,
                'terms' => $invoice->terms,
            ],
        ];
    }
}
