<?php

namespace App\Services;

use App\Contracts\EmailDeliveryAdapter;
use App\Enums\CommunicationChannel;
use App\Enums\ContactStatus;
use App\Enums\ConversationMode;
use App\Enums\DocumentType;
use App\Enums\EmailTemplateKey;
use App\Enums\InvoiceLifecycleStatus;
use App\Enums\MessageActorType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Jobs\SendOutboundEmailJob;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\User;
use App\Support\EmailDeliveryPayload;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Queues and delivers outbound document emails through the Communication domain.
 *
 * Recurring invoice generation must NOT call this service automatically.
 */
class EmailOutboundService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly DocumentService $documents,
        private readonly EmailDeliveryAdapter $delivery,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function queueQuoteEmail(Quote $quote, User $actor): Message
    {
        if ($quote->status === QuoteStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Draft quotes cannot be emailed. Issue the quote first.',
            ]);
        }

        $quote->loadMissing(['contact', 'documents']);

        return $this->queueDocumentEmail(
            documentable: $quote,
            contact: $quote->contact,
            templateKey: EmailTemplateKey::Quote,
            documentType: DocumentType::QuotePdf,
            actor: $actor,
            ensureDocument: fn () => $this->documents->generateQuotePdf($quote, $actor, true)['document'],
        );
    }

    public function queueInvoiceEmail(Invoice $invoice, User $actor): Message
    {
        if ($invoice->lifecycle_status === InvoiceLifecycleStatus::Draft) {
            throw ValidationException::withMessages([
                'lifecycle_status' => 'Draft invoices cannot be emailed. Issue the invoice first.',
            ]);
        }

        if ($invoice->lifecycle_status === InvoiceLifecycleStatus::Cancelled) {
            throw ValidationException::withMessages([
                'lifecycle_status' => 'Cancelled invoices cannot be emailed.',
            ]);
        }

        $invoice->loadMissing(['contact', 'documents']);

        return $this->queueDocumentEmail(
            documentable: $invoice,
            contact: $invoice->contact,
            templateKey: EmailTemplateKey::Invoice,
            documentType: DocumentType::InvoicePdf,
            actor: $actor,
            ensureDocument: fn () => $this->documents->generateInvoicePdf($invoice, $actor, true)['document'],
        );
    }

    public function queuePaymentAcknowledgementEmail(Payment $payment, User $actor): Message
    {
        if ($payment->status !== PaymentStatus::Confirmed) {
            throw ValidationException::withMessages([
                'status' => 'Only confirmed payments can email acknowledgements.',
            ]);
        }

        $payment->loadMissing(['contact', 'invoice', 'documents']);

        return $this->queueDocumentEmail(
            documentable: $payment,
            contact: $payment->contact,
            templateKey: EmailTemplateKey::PaymentAcknowledgement,
            documentType: DocumentType::PaymentAcknowledgementPdf,
            actor: $actor,
            ensureDocument: fn () => $this->documents->generatePaymentAcknowledgementPdf($payment, $actor, true)['document'],
        );
    }

    /**
     * Queue an invoice reminder email (outstanding balance + due date context).
     */
    public function queueInvoiceReminderEmail(Invoice $invoice, User $actor): Message
    {
        if ($invoice->lifecycle_status === InvoiceLifecycleStatus::Draft) {
            throw ValidationException::withMessages([
                'lifecycle_status' => 'Draft invoices cannot be reminded.',
            ]);
        }

        if ($invoice->lifecycle_status === InvoiceLifecycleStatus::Cancelled) {
            throw ValidationException::withMessages([
                'lifecycle_status' => 'Cancelled invoices cannot be reminded.',
            ]);
        }

        $invoice->loadMissing(['contact', 'documents']);

        return $this->queueDocumentEmail(
            documentable: $invoice,
            contact: $invoice->contact,
            templateKey: EmailTemplateKey::InvoiceReminder,
            documentType: DocumentType::InvoicePdf,
            actor: $actor,
            ensureDocument: fn () => $this->documents->generateInvoicePdf($invoice, $actor, true)['document'],
            actorType: MessageActorType::System,
        );
    }

    public function retry(Message $message, User $actor): Message
    {
        if ($message->channel !== CommunicationChannel::Email) {
            throw ValidationException::withMessages([
                'message' => 'Only email messages can be retried through email delivery.',
            ]);
        }

        if ($message->status->isTerminalSuccess()) {
            throw ValidationException::withMessages([
                'message' => 'This email was already submitted successfully. Queue a new send if another copy is required.',
            ]);
        }

        if (! $message->status->canRetryDelivery() && $message->status !== MessageStatus::Processing) {
            throw ValidationException::withMessages([
                'message' => 'This message cannot be retried in its current state.',
            ]);
        }

        // Processing left stuck (worker crash) may be retried by staff.
        if ($message->status === MessageStatus::Processing) {
            $message->status = MessageStatus::Failed;
            $message->failure_reason = $message->failure_reason ?: 'Previous send attempt did not complete.';
            $message->failed_at = now();
            $message->save();
        }

        $message->status = MessageStatus::Pending;
        $message->failure_reason = null;
        $message->failed_at = null;
        $message->save();

        SendOutboundEmailJob::dispatch($message->id);

        $this->auditLogger->record(
            event: 'email.retry_queued',
            description: 'Outbound email retry queued',
            auditable: $message,
            newValues: [
                'message_id' => $message->id,
                'document_id' => $message->document_id,
            ],
            actor: $actor,
        );

        return $message->refresh();
    }

    /**
     * Deliver a queued message. Safe under job retries / concurrent workers.
     */
    public function deliverQueuedMessage(Message $message): void
    {
        $locked = DB::transaction(function () use ($message) {
            /** @var Message|null $row */
            $row = Message::query()->whereKey($message->id)->lockForUpdate()->first();

            if ($row === null) {
                return null;
            }

            if ($row->status->isTerminalSuccess()) {
                return null;
            }

            if ($row->status === MessageStatus::Processing) {
                // Another worker owns this attempt.
                return null;
            }

            if ($row->status !== MessageStatus::Pending && $row->status !== MessageStatus::Failed) {
                return null;
            }

            $row->status = MessageStatus::Processing;
            $row->failure_reason = null;
            $row->save();

            return $row->fresh(['conversation.identity', 'document']);
        });

        if ($locked === null) {
            return;
        }

        try {
            $this->assertDeliveryEnabled();

            $payload = $this->buildPayload($locked);
            $result = $this->delivery->send($payload);

            if (! $result->success) {
                $this->markFailed($locked, $result->failureReason ?? 'Email delivery failed.');

                return;
            }

            DB::transaction(function () use ($locked, $result) {
                /** @var Message $row */
                $row = Message::query()->whereKey($locked->id)->lockForUpdate()->firstOrFail();

                if ($row->status->isTerminalSuccess()) {
                    return;
                }

                $row->status = MessageStatus::Sent;
                $row->external_message_id = $result->providerMessageId;
                $row->sent_at = now();
                $row->failed_at = null;
                $row->failure_reason = null;
                $row->save();
            });

            $this->auditLogger->record(
                event: 'email.sent',
                description: 'Outbound email submitted to provider',
                auditable: $locked->fresh(),
                newValues: [
                    'message_id' => $locked->id,
                    'document_id' => $locked->document_id,
                    'provider_message_id' => $result->providerMessageId,
                ],
            );
        } catch (ValidationException $e) {
            $reason = collect($e->errors())->flatten()->first() ?: 'Email delivery validation failed.';
            $this->markFailed($locked, is_string($reason) ? $reason : 'Email delivery validation failed.');
        } catch (\Throwable $e) {
            report($e);
            $this->markFailed($locked, 'Email delivery failed unexpectedly. Please retry later.');
            throw $e;
        }
    }

    /**
     * @param  Quote|Invoice|Payment  $documentable
     * @param  callable(): Document  $ensureDocument
     */
    private function queueDocumentEmail(
        Quote|Invoice|Payment $documentable,
        ?Contact $contact,
        EmailTemplateKey $templateKey,
        DocumentType $documentType,
        User $actor,
        callable $ensureDocument,
        MessageActorType $actorType = MessageActorType::Staff,
    ): Message {
        $this->assertDeliveryEnabled();
        $contact = $this->requireEligibleContact($contact);
        $email = $this->requireValidEmail($contact);

        $document = $documentable->documents
            ->where('type', $documentType)
            ->sortByDesc('id')
            ->first();

        if ($document === null) {
            $document = $ensureDocument();
            $documentable->unsetRelation('documents');
        }

        $link = $this->documents->createSecureLink($document, $actor);
        $plain = $link['plain_token'];
        $secureUrl = url('/d/'.$plain);

        $identity = $this->conversations->findOrCreateIdentity(
            channel: CommunicationChannel::Email,
            externalId: $email,
            displayName: $contact->display_name,
            contact: $contact,
        );

        if ($identity->contact_id === null) {
            $this->conversations->linkIdentityToContact($identity, $contact);
            $identity->refresh();
        } elseif ((int) $identity->contact_id !== (int) $contact->id) {
            // Prefer linking to this customer when identity was orphaned incorrectly.
            // Do not steal an identity already linked to a different contact.
            throw ValidationException::withMessages([
                'email' => 'This email identity is already linked to a different contact.',
            ]);
        }

        if (! $identity->is_active) {
            throw ValidationException::withMessages([
                'email' => 'Email communication is disabled for this identity.',
            ]);
        }

        $conversation = $this->conversations->openConversation(
            identity: $identity,
            mode: ConversationMode::Human,
            subject: $this->conversationSubject($templateKey, $documentable),
        );

        if ($conversation->isClosed()) {
            $conversation = $this->conversations->reopen($conversation, ConversationMode::Human);
        }

        $viewContext = $this->buildViewContext($templateKey, $documentable, $contact, $secureUrl);
        $body = $this->plainBodySummary($viewContext);

        $message = DB::transaction(function () use (
            $conversation,
            $actor,
            $actorType,
            $body,
            $viewContext,
            $templateKey,
            $document,
            $email,
            $secureUrl,
        ) {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => MessageDirection::Outbound,
                'channel' => CommunicationChannel::Email,
                'body' => $body,
                'subject' => $viewContext['subject'],
                'template_key' => $templateKey->value,
                'document_id' => $document->id,
                'status' => MessageStatus::Pending,
                'actor_type' => $actorType,
                'actor_user_id' => $actor->id,
                'external_message_id' => null,
                'occurred_at' => now(),
                'meta' => [
                    'to' => $email,
                    'secure_url' => $secureUrl,
                    'document_number' => $viewContext['document_number'],
                    'template' => $templateKey->value,
                ],
            ]);

            $conversation->last_message_at = $message->occurred_at;
            $conversation->save();

            return $message;
        });

        SendOutboundEmailJob::dispatch($message->id);

        $this->auditLogger->record(
            event: 'email.queued',
            description: 'Outbound document email queued',
            auditable: $message,
            newValues: [
                'message_id' => $message->id,
                'document_id' => $document->id,
                'template' => $templateKey->value,
                'to' => $email,
            ],
            actor: $actor,
        );

        return $message->refresh();
    }

    private function buildPayload(Message $message): EmailDeliveryPayload
    {
        $message->loadMissing(['conversation.identity', 'document.documentable']);

        $identity = $message->conversation?->identity;
        $to = is_array($message->meta) && isset($message->meta['to'])
            ? (string) $message->meta['to']
            : (string) ($identity?->external_id ?? '');

        $to = $this->normalizeEmail($to);
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'Recipient email address is missing or invalid.',
            ]);
        }

        $document = $message->document;
        if ($document === null) {
            throw ValidationException::withMessages([
                'document' => 'Linked document is missing for this email.',
            ]);
        }

        $templateKey = EmailTemplateKey::from((string) $message->template_key);
        $documentable = $document->documentable;
        if (! $documentable instanceof Quote && ! $documentable instanceof Invoice && ! $documentable instanceof Payment) {
            throw ValidationException::withMessages([
                'document' => 'Unsupported document type for email delivery.',
            ]);
        }

        $contact = $documentable->contact ?? $message->conversation?->contact;
        $secureUrl = is_array($message->meta) ? (string) ($message->meta['secure_url'] ?? '') : '';

        // Secure link remains available for browser viewing; the PDF itself is attached.
        if ($secureUrl === '') {
            $link = $this->documents->createSecureLink($document, $message->actorUser ?? User::query()->findOrFail($message->actor_user_id));
            $secureUrl = url('/d/'.$link['plain_token']);
            $meta = $message->meta ?? [];
            $meta['secure_url'] = $secureUrl;
            $message->meta = $meta;
            $message->save();
        } elseif (! $document->isAccessActive()) {
            $link = $this->documents->createSecureLink($document, $message->actorUser ?? User::query()->findOrFail($message->actor_user_id));
            $secureUrl = url('/d/'.$link['plain_token']);
            $meta = $message->meta ?? [];
            $meta['secure_url'] = $secureUrl;
            $message->meta = $meta;
            $message->save();
        }

        if (! Storage::disk($document->disk)->exists($document->path)) {
            throw ValidationException::withMessages([
                'document' => 'The PDF file is missing from storage and cannot be attached.',
            ]);
        }

        $viewContext = $this->buildViewContext(
            $templateKey,
            $documentable,
            $contact instanceof Contact ? $contact : null,
            $secureUrl,
        );

        [$fromAddress, $fromName, $replyTo] = $this->resolveSender();

        return new EmailDeliveryPayload(
            toAddress: $to,
            toName: $identity?->display_name ?: ($contact?->display_name ?? $to),
            subject: (string) ($message->subject ?: $viewContext['subject']),
            templateKey: $templateKey,
            viewData: $viewContext,
            fromAddress: $fromAddress,
            fromName: $fromName,
            replyTo: $replyTo,
            messageId: $message->id,
            attachmentDisk: $document->disk,
            attachmentPath: $document->path,
            attachmentFilename: $document->filename,
            attachmentMime: $document->mime_type ?: 'application/pdf',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewContext(
        EmailTemplateKey $templateKey,
        Quote|Invoice|Payment $documentable,
        ?Contact $contact,
        string $secureUrl,
    ): array {
        $business = Business::current();
        $customerName = $contact?->display_name
            ?? (is_array($documentable->customer_snapshot ?? null)
                ? (string) (($documentable->customer_snapshot['display_name'] ?? '') ?: '')
                : '');

        $base = [
            'business_name' => $business->name,
            'customer_name' => $customerName !== '' ? $customerName : null,
            'secure_url' => $secureUrl,
            'cta_label' => 'View in browser',
            'pdf_attached' => true,
        ];

        return match ($templateKey) {
            EmailTemplateKey::Quote => [
                ...$base,
                'subject' => 'Quote '.$documentable->number.' from '.$business->name,
                'headline' => 'Your quote is ready',
                'intro' => 'Your quote PDF is attached to this email. You can also open a secure browser copy using the link below.',
                'document_label' => 'Quote',
                'document_number' => $documentable->number,
                'amount_label' => 'Total',
                'amount' => $this->formatMoney((string) $documentable->total, (string) $documentable->currency_code),
                'due_date' => $documentable->expiry_date?->toDateString(),
            ],
            EmailTemplateKey::Invoice => [
                ...$base,
                'subject' => 'Invoice '.$documentable->number.' from '.$business->name,
                'headline' => 'Your invoice is ready',
                'intro' => 'Your invoice PDF is attached to this email. You can also open a secure browser copy using the link below.',
                'document_label' => 'Invoice',
                'document_number' => $documentable->number,
                'amount_label' => 'Amount due',
                'amount' => $this->formatMoney((string) $documentable->balance_due, (string) $documentable->currency_code),
                'due_date' => $documentable->due_date?->toDateString(),
            ],
            EmailTemplateKey::InvoiceReminder => $this->invoiceReminderContext($base, $documentable, $business),
            EmailTemplateKey::PaymentAcknowledgement => [
                ...$base,
                'subject' => 'Payment acknowledgement '.$documentable->number.' from '.$business->name,
                'headline' => 'Payment acknowledgement',
                'intro' => 'Thank you. Your payment acknowledgement PDF is attached. You can also open a secure browser copy using the link below.',
                'document_label' => 'Acknowledgement',
                'document_number' => $documentable->number,
                'amount_label' => 'Amount received',
                'amount' => $this->formatMoney((string) $documentable->amount, (string) $documentable->currency_code),
                'related_invoice_number' => $documentable->invoice?->number,
                'due_date' => null,
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $viewContext
     */
    private function plainBodySummary(array $viewContext): string
    {
        $parts = [
            (string) ($viewContext['headline'] ?? 'Document'),
            (string) ($viewContext['document_label'] ?? 'Document').': '.(string) ($viewContext['document_number'] ?? ''),
        ];

        if (! empty($viewContext['amount'])) {
            $parts[] = ((string) ($viewContext['amount_label'] ?? 'Amount')).': '.$viewContext['amount'];
        }

        $parts[] = 'Secure link included in email.';

        return implode("\n", $parts);
    }

    private function conversationSubject(EmailTemplateKey $templateKey, Quote|Invoice|Payment $documentable): string
    {
        return match ($templateKey) {
            EmailTemplateKey::Quote => 'Quote '.$documentable->number,
            EmailTemplateKey::Invoice, EmailTemplateKey::InvoiceReminder => 'Invoice '.$documentable->number,
            EmailTemplateKey::PaymentAcknowledgement => 'Payment '.$documentable->number,
        };
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function invoiceReminderContext(array $base, Invoice $invoice, Business $business): array
    {
        $dueState = $invoice->dueState();
        $overdue = $dueState === \App\Enums\InvoiceDueState::Overdue;

        return [
            ...$base,
            'subject' => ($overdue ? 'Overdue invoice ' : 'Invoice reminder: ').$invoice->number.' from '.$business->name,
            'headline' => $overdue ? 'Invoice payment reminder' : 'Upcoming invoice due',
            'intro' => ($overdue
                ? 'This is a friendly reminder that the following invoice is overdue. The outstanding balance is shown below. The invoice PDF is attached.'
                : 'This is a friendly reminder that the following invoice is coming due. The outstanding balance is shown below. The invoice PDF is attached.'),
            'document_label' => 'Invoice',
            'document_number' => $invoice->number,
            'amount_label' => 'Outstanding balance',
            'amount' => $this->formatMoney((string) $invoice->balance_due, (string) $invoice->currency_code),
            'due_date' => $invoice->due_date?->toDateString(),
            'cta_label' => 'View in browser',
        ];
    }

    private function requireEligibleContact(?Contact $contact): Contact
    {
        if ($contact === null) {
            throw ValidationException::withMessages([
                'contact' => 'A customer contact is required to send email.',
            ]);
        }

        if ($contact->isArchived()) {
            throw ValidationException::withMessages([
                'contact' => 'Cannot email an archived contact.',
            ]);
        }

        if ($contact->status !== ContactStatus::Customer) {
            throw ValidationException::withMessages([
                'contact' => 'Email document delivery requires a Customer contact.',
            ]);
        }

        return $contact;
    }

    private function requireValidEmail(Contact $contact): string
    {
        $email = $this->normalizeEmail((string) ($contact->email ?? ''));

        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => 'This customer does not have an email address.',
            ]);
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'This customer has an invalid email address.',
            ]);
        }

        return $email;
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function assertDeliveryEnabled(): void
    {
        if (! (bool) config('adman.email.enabled', true)) {
            throw ValidationException::withMessages([
                'email' => 'Outbound email is disabled for this deployment.',
            ]);
        }

        $business = Business::current();
        if (! $business->outbound_email_enabled) {
            throw ValidationException::withMessages([
                'email' => 'Outbound email is disabled in business settings.',
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function resolveSender(): array
    {
        $business = Business::current();
        $configFrom = (string) config('mail.from.address', '');
        $configName = (string) config('mail.from.name', $business->name);

        $fromAddress = $configFrom;
        if (filled($business->email) && filter_var($business->email, FILTER_VALIDATE_EMAIL)) {
            // Prefer org email when set; still requires MAIL_* transport credentials.
            $fromAddress = (string) $business->email;
        }

        if ($fromAddress === '' || ! filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'Sender email is not configured. Set Business email or MAIL_FROM_ADDRESS.',
            ]);
        }

        $fromName = filled($business->name) ? $business->name : $configName;

        $replyTo = null;
        if (filled($business->email_reply_to) && filter_var($business->email_reply_to, FILTER_VALIDATE_EMAIL)) {
            $replyTo = (string) $business->email_reply_to;
        } elseif (filled($business->email) && filter_var($business->email, FILTER_VALIDATE_EMAIL)) {
            $replyTo = (string) $business->email;
        }

        return [$fromAddress, $fromName, $replyTo];
    }

    private function formatMoney(string $amount, string $currency): string
    {
        return $currency.' '.Money::normalize($amount);
    }

    private function markFailed(Message $message, string $reason): void
    {
        DB::transaction(function () use ($message, $reason) {
            /** @var Message $row */
            $row = Message::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();

            if ($row->status->isTerminalSuccess()) {
                return;
            }

            $row->status = MessageStatus::Failed;
            $row->failure_reason = $reason;
            $row->failed_at = now();
            $row->save();
        });

        $this->auditLogger->record(
            event: 'email.failed',
            description: 'Outbound email delivery failed',
            auditable: $message->fresh(),
            newValues: [
                'message_id' => $message->id,
                'document_id' => $message->document_id,
            ],
            meta: [
                'failure_reason' => $reason,
            ],
        );
    }
}
