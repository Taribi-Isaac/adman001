<?php

namespace App\Ai\Tools;

use App\Enums\PaymentStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Payment;
use App\Services\Ai\AiAuthorization;

final class GetInvoicePaymentStatusTool implements AiTool
{
    public function __construct(private readonly AiAuthorization $auth) {}

    public function name(): string
    {
        return 'get_invoice_payment_status';
    }

    public function description(): string
    {
        return 'Get authoritative payment status and outstanding balance for a customer invoice.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'invoice_number' => ['type' => 'string'],
            ],
            'required' => ['invoice_number'],
            'additionalProperties' => false,
        ];
    }

    public function execute(Conversation $conversation, Message $inbound, array $arguments): array
    {
        $invoice = $this->auth->authorizedInvoice($conversation, (string) ($arguments['invoice_number'] ?? ''));
        if ($invoice === null) {
            return ['ok' => false, 'error' => 'Invoice not found or not authorized.'];
        }

        return [
            'ok' => true,
            'invoice_number' => $invoice->number,
            'payment_status' => $invoice->payment_status->value,
            'payment_status_label' => $invoice->payment_status->label(),
            'balance_due' => (string) $invoice->balance_due,
            'amount_paid' => (string) $invoice->amount_paid,
            'total' => (string) $invoice->total,
            'currency_code' => $invoice->currency_code,
            'note' => 'Customer statements that they paid do not change this status until staff confirms a payment.',
        ];
    }
}
