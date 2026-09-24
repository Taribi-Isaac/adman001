<?php

namespace App\Ai\Tools;

use App\Enums\PaymentStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Payment;
use App\Services\Ai\AiAuthorization;

final class GetInvoicePaymentsTool implements AiTool
{
    public function __construct(private readonly AiAuthorization $auth) {}

    public function name(): string
    {
        return 'get_invoice_payments';
    }

    public function description(): string
    {
        return 'List confirmed and pending recorded payments for a customer invoice. Payment claims are separate.';
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

        $payments = Payment::query()
            ->where('invoice_id', $invoice->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return [
            'ok' => true,
            'invoice_number' => $invoice->number,
            'payments' => $payments->map(fn (Payment $payment) => [
                'number' => $payment->number,
                'amount' => (string) $payment->amount,
                'currency_code' => $payment->currency_code,
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'payment_date' => $payment->payment_date?->toDateString(),
                'payment_method' => $payment->payment_method?->value,
                'is_confirmed' => $payment->status === PaymentStatus::Confirmed,
            ])->all(),
        ];
    }
}
