<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\InvoiceLifecycleStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\User;
use App\Support\DocumentPresentation;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentService
{
    public const SECURE_LINK_TTL_DAYS = 90;

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{document: Document, plain_token: string|null}
     */
    public function generateQuotePdf(Quote $quote, User $actor, bool $createSecureLink = true): array
    {
        if ($quote->status === QuoteStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Draft quotes cannot have public documents. Issue the quote first.',
            ]);
        }

        $quote->loadMissing('items');
        $business = $quote->business_snapshot ?? [];

        $pdf = Pdf::loadView('documents.quote', [
            'quote' => $quote,
            'business' => $business,
            'customer' => $quote->customer_snapshot ?? [],
            'items' => $quote->items,
            'logoSrc' => DocumentPresentation::logoDataUri(
                is_string($business['logo_path'] ?? null) ? $business['logo_path'] : null
            ),
        ]);

        return $this->storePdf(
            type: DocumentType::QuotePdf,
            documentable: $quote,
            pdfBinary: $pdf->output(),
            filename: $quote->number.'.pdf',
            actor: $actor,
            createSecureLink: $createSecureLink,
        );
    }

    /**
     * @return array{document: Document, plain_token: string|null}
     */
    public function generateInvoicePdf(Invoice $invoice, User $actor, bool $createSecureLink = true): array
    {
        if ($invoice->lifecycle_status === InvoiceLifecycleStatus::Draft) {
            throw ValidationException::withMessages([
                'lifecycle_status' => 'Draft invoices cannot have public documents. Issue the invoice first.',
            ]);
        }

        $invoice->loadMissing('items');
        $business = $invoice->business_snapshot ?? [];

        $pdf = Pdf::loadView('documents.invoice', [
            'invoice' => $invoice,
            'business' => $business,
            'customer' => $invoice->customer_snapshot ?? [],
            'items' => $invoice->items,
            'display' => $invoice->displayStatus(),
            'dueState' => $invoice->dueState(),
            'logoSrc' => DocumentPresentation::logoDataUri(
                is_string($business['logo_path'] ?? null) ? $business['logo_path'] : null
            ),
        ]);

        return $this->storePdf(
            type: DocumentType::InvoicePdf,
            documentable: $invoice,
            pdfBinary: $pdf->output(),
            filename: $invoice->number.'.pdf',
            actor: $actor,
            createSecureLink: $createSecureLink,
        );
    }

    /**
     * @return array{document: Document, plain_token: string|null}
     */
    public function generatePaymentAcknowledgementPdf(Payment $payment, User $actor, bool $createSecureLink = true): array
    {
        if ($payment->status !== PaymentStatus::Confirmed) {
            throw ValidationException::withMessages([
                'status' => 'Only confirmed payments can generate acknowledgements.',
            ]);
        }

        $payment->loadMissing(['invoice', 'contact']);
        $invoice = $payment->invoice;
        $invoice->loadMissing('payments');

        $amountPaidAfter = (string) ($payment->invoice_snapshot['amount_paid_after'] ?? $invoice->amount_paid);
        $balanceAfter = (string) ($payment->invoice_snapshot['balance_due_after'] ?? $invoice->balance_due);
        $isPartial = Money::compare($balanceAfter, '0') > 0;
        $business = $payment->business_snapshot ?? [];

        $pdf = Pdf::loadView('documents.payment-acknowledgement', [
            'payment' => $payment,
            'invoice' => $invoice,
            'business' => $business,
            'customer' => $payment->customer_snapshot ?? [],
            'amount_paid_after' => $amountPaidAfter,
            'balance_due_after' => $balanceAfter,
            'is_partial' => $isPartial,
            'invoice_total' => (string) ($payment->invoice_snapshot['total'] ?? $invoice->total),
            'logoSrc' => DocumentPresentation::logoDataUri(
                is_string($business['logo_path'] ?? null) ? $business['logo_path'] : null
            ),
        ]);

        return $this->storePdf(
            type: DocumentType::PaymentAcknowledgementPdf,
            documentable: $payment,
            pdfBinary: $pdf->output(),
            filename: $payment->number.'-acknowledgement.pdf',
            actor: $actor,
            createSecureLink: $createSecureLink,
        );
    }

    /**
     * @return array{document: Document, plain_token: string|null}
     */
    public function createSecureLink(Document $document, User $actor): array
    {
        if ($document->access_revoked_at !== null) {
            $document->access_revoked_at = null;
        }

        $plain = Str::random(64);
        $document->access_token_hash = hash('sha256', $plain);
        $document->access_expires_at = now()->addDays(self::SECURE_LINK_TTL_DAYS);
        $document->save();

        $this->auditLogger->record(
            event: 'document.secure_link_created',
            description: 'Secure document link created',
            auditable: $document,
            newValues: [
                'expires_at' => $document->access_expires_at?->toIso8601String(),
            ],
            actor: $actor,
        );

        return ['document' => $document, 'plain_token' => $plain];
    }

    public function revokeSecureLink(Document $document, User $actor): Document
    {
        $document->access_revoked_at = now();
        $document->save();

        $this->auditLogger->record(
            event: 'document.secure_link_revoked',
            description: 'Secure document link revoked',
            auditable: $document,
            actor: $actor,
        );

        return $document->refresh();
    }

    public function findByPlainToken(string $token): ?Document
    {
        if ($token === '' || strlen($token) < 32) {
            return null;
        }

        $hash = hash('sha256', $token);

        return Document::query()->where('access_token_hash', $hash)->first();
    }

    /**
     * @return array{document: Document, plain_token: string|null}
     */
    private function storePdf(
        DocumentType $type,
        Quote|Invoice|Payment $documentable,
        string $pdfBinary,
        string $filename,
        User $actor,
        bool $createSecureLink,
    ): array {
        $disk = 'local';
        $path = 'documents/'.$type->value.'/'.$documentable->getMorphClass().'/'.$documentable->getKey().'/'.Str::uuid().'.pdf';

        Storage::disk($disk)->put($path, $pdfBinary);

        $plain = null;
        $hash = null;
        $expires = null;

        if ($createSecureLink) {
            $plain = Str::random(64);
            $hash = hash('sha256', $plain);
            $expires = now()->addDays(self::SECURE_LINK_TTL_DAYS);
        }

        $document = Document::query()->create([
            'type' => $type,
            'documentable_type' => $documentable->getMorphClass(),
            'documentable_id' => $documentable->getKey(),
            'disk' => $disk,
            'path' => $path,
            'filename' => $filename,
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($pdfBinary),
            'access_token_hash' => $hash,
            'access_expires_at' => $expires,
            'access_revoked_at' => null,
            'generated_by' => $actor->id,
            'generated_at' => now(),
            'meta' => [
                'number' => $documentable->number,
            ],
        ]);

        $this->auditLogger->record(
            event: 'document.generated',
            description: 'Document PDF generated',
            auditable: $document,
            newValues: [
                'type' => $type->value,
                'filename' => $filename,
                'secure_link' => $createSecureLink,
            ],
            actor: $actor,
        );

        if ($createSecureLink) {
            $this->auditLogger->record(
                event: 'document.secure_link_created',
                description: 'Secure document link created with PDF',
                auditable: $document,
                newValues: [
                    'expires_at' => $expires?->toIso8601String(),
                ],
                actor: $actor,
            );
        }

        return ['document' => $document, 'plain_token' => $plain];
    }
}
