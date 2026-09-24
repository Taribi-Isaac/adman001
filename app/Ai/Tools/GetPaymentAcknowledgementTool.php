<?php

namespace App\Ai\Tools;

use App\Enums\DocumentType;
use App\Enums\PaymentStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Payment;
use App\Services\Ai\AiAuthorization;

final class GetPaymentAcknowledgementTool implements AiTool
{
    public function __construct(private readonly AiAuthorization $auth) {}

    public function name(): string
    {
        return 'get_payment_acknowledgement';
    }

    public function description(): string
    {
        return 'Explain a confirmed payment acknowledgement for the authorized customer by payment/receipt number.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'payment_number' => [
                    'type' => 'string',
                    'description' => 'Payment/receipt number',
                ],
            ],
            'required' => ['payment_number'],
            'additionalProperties' => false,
        ];
    }

    public function execute(Conversation $conversation, Message $inbound, array $arguments): array
    {
        $contact = $this->auth->authorizedContact($conversation);
        if ($contact === null) {
            return ['ok' => false, 'error' => 'Payment acknowledgement data is not available for this sender.'];
        }

        $number = trim((string) ($arguments['payment_number'] ?? ''));
        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->where('contact_id', $contact->id)
            ->where('number', $number)
            ->first();

        if ($payment === null) {
            return ['ok' => false, 'error' => 'Payment not found for this customer.'];
        }

        if ($payment->status !== PaymentStatus::Confirmed) {
            return [
                'ok' => true,
                'payment_number' => $payment->number,
                'status' => $payment->status->value,
                'note' => 'This payment is not confirmed yet, so an acknowledgement is not final.',
            ];
        }

        $hasDoc = $payment->documents()
            ->where('type', DocumentType::PaymentAcknowledgementPdf->value)
            ->exists();

        return [
            'ok' => true,
            'payment_number' => $payment->number,
            'amount' => (string) $payment->amount,
            'currency_code' => $payment->currency_code,
            'payment_date' => $payment->payment_date?->toDateString(),
            'invoice_number' => $payment->invoice?->number,
            'acknowledgement_document_available' => $hasDoc,
            'note' => 'Use get_secure_document_link with document_type payment_acknowledgement and this payment_number to share a secure link.',
        ];
    }
}
