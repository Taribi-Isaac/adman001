<?php

namespace App\Ai\Tools;

use App\Enums\DocumentType;
use App\Enums\InvoiceLifecycleStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Models\Conversation;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\User;
use App\Services\Ai\AiAuthorization;
use App\Services\DocumentService;

final class GetSecureDocumentLinkTool implements AiTool
{
    public function __construct(
        private readonly AiAuthorization $auth,
        private readonly DocumentService $documents,
    ) {}

    public function name(): string
    {
        return 'get_secure_document_link';
    }

    public function description(): string
    {
        return 'Create a secure time-limited document link for an authorized customer invoice, quote, or payment acknowledgement. Never invent URLs.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'document_type' => [
                    'type' => 'string',
                    'enum' => ['invoice', 'quote', 'payment_acknowledgement'],
                ],
                'number' => [
                    'type' => 'string',
                    'description' => 'Invoice, quote, or payment number',
                ],
            ],
            'required' => ['document_type', 'number'],
            'additionalProperties' => false,
        ];
    }

    public function execute(Conversation $conversation, Message $inbound, array $arguments): array
    {
        $contact = $this->auth->authorizedContact($conversation);
        if ($contact === null) {
            return ['ok' => false, 'error' => 'Secure document links require an authorized linked customer.'];
        }

        $type = (string) ($arguments['document_type'] ?? '');
        $number = trim((string) ($arguments['number'] ?? ''));
        $actor = $this->systemActor();

        try {
            return match ($type) {
                'invoice' => $this->invoiceLink($contact->id, $number, $actor),
                'quote' => $this->quoteLink($contact->id, $number, $actor),
                'payment_acknowledgement' => $this->paymentLink($contact->id, $number, $actor),
                default => ['ok' => false, 'error' => 'Unsupported document_type.'],
            };
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'error' => 'Unable to create a secure document link right now.'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceLink(int $contactId, string $number, User $actor): array
    {
        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()
            ->where('contact_id', $contactId)
            ->where('number', $number)
            ->where('lifecycle_status', '!=', InvoiceLifecycleStatus::Draft->value)
            ->first();

        if ($invoice === null) {
            return ['ok' => false, 'error' => 'Invoice not found for this customer.'];
        }

        $document = $invoice->documents()
            ->where('type', DocumentType::InvoicePdf->value)
            ->orderByDesc('id')
            ->first();

        if ($document === null) {
            $generated = $this->documents->generateInvoicePdf($invoice, $actor, true);
            $document = $generated['document'];
        }

        $link = $this->documents->createSecureLink($document, $actor);

        return [
            'ok' => true,
            'document_type' => 'invoice',
            'number' => $invoice->number,
            'secure_url' => url('/d/'.$link['plain_token']),
            'note' => 'This link expires according to ADMAN document access rules.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteLink(int $contactId, string $number, User $actor): array
    {
        /** @var Quote|null $quote */
        $quote = Quote::query()
            ->where('contact_id', $contactId)
            ->where('number', $number)
            ->where('status', '!=', QuoteStatus::Draft->value)
            ->first();

        if ($quote === null) {
            return ['ok' => false, 'error' => 'Quote not found for this customer.'];
        }

        $document = $quote->documents()
            ->where('type', DocumentType::QuotePdf->value)
            ->orderByDesc('id')
            ->first();

        if ($document === null) {
            $generated = $this->documents->generateQuotePdf($quote, $actor, true);
            $document = $generated['document'];
        }

        $link = $this->documents->createSecureLink($document, $actor);

        return [
            'ok' => true,
            'document_type' => 'quote',
            'number' => $quote->number,
            'secure_url' => url('/d/'.$link['plain_token']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentLink(int $contactId, string $number, User $actor): array
    {
        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->where('contact_id', $contactId)
            ->where('number', $number)
            ->first();

        if ($payment === null || $payment->status !== PaymentStatus::Confirmed) {
            return ['ok' => false, 'error' => 'Confirmed payment acknowledgement not available for that number.'];
        }

        $document = $payment->documents()
            ->where('type', DocumentType::PaymentAcknowledgementPdf->value)
            ->orderByDesc('id')
            ->first();

        if ($document === null) {
            $generated = $this->documents->generatePaymentAcknowledgementPdf($payment, $actor, true);
            $document = $generated['document'];
        }

        $link = $this->documents->createSecureLink($document, $actor);

        return [
            'ok' => true,
            'document_type' => 'payment_acknowledgement',
            'number' => $payment->number,
            'secure_url' => url('/d/'.$link['plain_token']),
        ];
    }

    private function systemActor(): User
    {
        $user = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', User::ROLE_SUPER_ADMINISTRATOR))
            ->orderBy('id')
            ->first();

        return $user ?? User::query()->orderBy('id')->firstOrFail();
    }
}
