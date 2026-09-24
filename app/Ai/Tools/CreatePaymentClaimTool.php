<?php

namespace App\Ai\Tools;

use App\Enums\InvoiceLifecycleStatus;
use App\Enums\PaymentMethod;
use App\Models\Conversation;
use App\Models\Invoice;
use App\Models\Message;
use App\Services\Ai\AiAuthorization;
use App\Services\AuditLogger;
use App\Services\PaymentService;
use Illuminate\Validation\ValidationException;

final class CreatePaymentClaimTool implements AiTool
{
    public function __construct(
        private readonly AiAuthorization $auth,
        private readonly PaymentService $payments,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function name(): string
    {
        return 'create_payment_claim';
    }

    public function description(): string
    {
        return 'Create a payment claim awaiting staff verification. Never confirms payment. If multiple outstanding invoices exist and invoice_number is omitted, ask the customer which invoice — do not guess.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'invoice_number' => [
                    'type' => 'string',
                    'description' => 'Required when the customer has more than one outstanding invoice.',
                ],
                'claimed_amount' => [
                    'type' => 'string',
                    'description' => 'Amount the customer says they paid, e.g. 500.00',
                ],
                'claimed_payment_date' => [
                    'type' => 'string',
                    'description' => 'YYYY-MM-DD if known',
                ],
                'payment_method' => [
                    'type' => 'string',
                    'enum' => PaymentMethod::values(),
                ],
                'customer_reference' => ['type' => 'string'],
                'supporting_info' => ['type' => 'string'],
            ],
            'required' => ['claimed_amount'],
            'additionalProperties' => false,
        ];
    }

    public function execute(Conversation $conversation, Message $inbound, array $arguments): array
    {
        $contact = $this->auth->authorizedContact($conversation);
        if ($contact === null) {
            return ['ok' => false, 'error' => 'Cannot create a payment claim without a linked authorized customer.'];
        }

        $outstanding = Invoice::query()
            ->where('contact_id', $contact->id)
            ->where('lifecycle_status', InvoiceLifecycleStatus::Issued->value)
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Invoice $invoice) => $invoice->payment_status->isOutstanding()
                && (float) $invoice->balance_due > 0)
            ->values();

        $invoiceNumber = trim((string) ($arguments['invoice_number'] ?? ''));
        /** @var Invoice|null $invoice */
        $invoice = null;

        if ($invoiceNumber !== '') {
            $invoice = $this->auth->authorizedInvoice($conversation, $invoiceNumber);
            if ($invoice === null) {
                return ['ok' => false, 'error' => 'Invoice not found for this customer.'];
            }
        } elseif ($outstanding->count() === 1) {
            $invoice = $outstanding->first();
        } elseif ($outstanding->count() === 0) {
            return [
                'ok' => false,
                'error' => 'There are no outstanding invoices for this customer. Ask for clarification or escalate to a human.',
            ];
        } else {
            return [
                'ok' => false,
                'needs_clarification' => true,
                'error' => 'Multiple outstanding invoices exist. Ask which invoice number the payment applies to.',
                'outstanding_invoices' => $outstanding->map(fn (Invoice $i) => [
                    'number' => $i->number,
                    'balance_due' => (string) $i->balance_due,
                    'currency_code' => $i->currency_code,
                    'due_date' => $i->due_date?->toDateString(),
                ])->all(),
            ];
        }

        try {
            $claim = $this->payments->createClaim($invoice, [
                'claimed_amount' => (string) ($arguments['claimed_amount'] ?? ''),
                'claimed_payment_date' => $arguments['claimed_payment_date'] ?? null,
                'payment_method' => $arguments['payment_method'] ?? null,
                'customer_reference' => $arguments['customer_reference'] ?? null,
                'supporting_info' => $arguments['supporting_info'] ?? null,
                'source_channel' => 'ai_whatsapp',
            ], null);
        } catch (ValidationException $e) {
            return [
                'ok' => false,
                'error' => collect($e->errors())->flatten()->first() ?: 'Payment claim could not be created.',
            ];
        }

        $this->auditLogger->record(
            event: 'ai.payment_claim_created',
            description: 'AI created a payment claim awaiting verification',
            auditable: $claim,
            newValues: [
                'invoice_id' => $invoice->id,
                'claimed_amount' => $claim->claimed_amount,
                'inbound_message_id' => $inbound->id,
            ],
        );

        return [
            'ok' => true,
            'status' => 'pending_verification',
            'invoice_number' => $invoice->number,
            'claimed_amount' => (string) $claim->claimed_amount,
            'currency_code' => $invoice->currency_code,
            'message_for_customer' => 'Your payment information has been submitted for verification. It is not confirmed yet. Our team will review it.',
            'warning' => 'Do not tell the customer the invoice is paid.',
        ];
    }
}
