<?php

namespace App\Services;

use App\Contracts\WhatsAppDeliveryAdapter;
use App\Enums\CommunicationChannel;
use App\Enums\ContactStatus;
use App\Enums\ConversationMode;
use App\Enums\DocumentType;
use App\Enums\InvoiceLifecycleStatus;
use App\Enums\MessageActorType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Enums\WhatsAppTemplateKey;
use App\Jobs\SendOutboundWhatsAppJob;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\User;
use App\Support\WhatsAppDeliveryPayload;
use App\Support\WhatsAppDocumentPayload;
use App\Support\WhatsAppPhone;
use App\Support\WhatsAppTextPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Queues and delivers outbound WhatsApp messages through the Communication domain.
 *
 * Recurring invoice generation must NOT call this service automatically.
 * AI may only call {@see queueAiSessionReply()} for conversational session text.
 * AI must NOT call document/template send methods.
 */
class WhatsAppOutboundService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly DocumentService $documents,
        private readonly WhatsAppDeliveryAdapter $delivery,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function queueQuoteWhatsApp(Quote $quote, User $actor): Message
    {
        if ($quote->status === QuoteStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Draft quotes cannot be sent via WhatsApp. Issue the quote first.',
            ]);
        }

        $quote->loadMissing(['contact', 'documents']);

        return $this->queueDocumentWhatsApp(
            documentable: $quote,
            contact: $quote->contact,
            templateKey: WhatsAppTemplateKey::Quote,
            documentType: DocumentType::QuotePdf,
            actor: $actor,
            ensureDocument: fn () => $this->documents->generateQuotePdf($quote, $actor, true)['document'],
        );
    }

    public function queueInvoiceWhatsApp(Invoice $invoice, User $actor): Message
    {
        if ($invoice->lifecycle_status === InvoiceLifecycleStatus::Draft) {
            throw ValidationException::withMessages([
                'lifecycle_status' => 'Draft invoices cannot be sent via WhatsApp. Issue the invoice first.',
            ]);
        }

        if ($invoice->lifecycle_status === InvoiceLifecycleStatus::Cancelled) {
            throw ValidationException::withMessages([
                'lifecycle_status' => 'Cancelled invoices cannot be sent via WhatsApp.',
            ]);
        }

        $invoice->loadMissing(['contact', 'documents']);

        return $this->queueDocumentWhatsApp(
            documentable: $invoice,
            contact: $invoice->contact,
            templateKey: WhatsAppTemplateKey::Invoice,
            documentType: DocumentType::InvoicePdf,
            actor: $actor,
            ensureDocument: fn () => $this->documents->generateInvoicePdf($invoice, $actor, true)['document'],
        );
    }

    public function queuePaymentAcknowledgementWhatsApp(Payment $payment, User $actor): Message
    {
        if ($payment->status !== PaymentStatus::Confirmed) {
            throw ValidationException::withMessages([
                'status' => 'Only confirmed payments can send WhatsApp acknowledgements.',
            ]);
        }

        $payment->loadMissing(['contact', 'invoice', 'documents']);

        return $this->queueDocumentWhatsApp(
            documentable: $payment,
            contact: $payment->contact,
            templateKey: WhatsAppTemplateKey::PaymentAcknowledgement,
            documentType: DocumentType::PaymentAcknowledgementPdf,
            actor: $actor,
            ensureDocument: fn () => $this->documents->generatePaymentAcknowledgementPdf($payment, $actor, true)['document'],
        );
    }

    public function queueInvoiceReminderWhatsApp(Invoice $invoice, User $actor): Message
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

        return $this->queueDocumentWhatsApp(
            documentable: $invoice,
            contact: $invoice->contact,
            templateKey: WhatsAppTemplateKey::InvoiceReminder,
            documentType: DocumentType::InvoicePdf,
            actor: $actor,
            ensureDocument: fn () => $this->documents->generateInvoicePdf($invoice, $actor, true)['document'],
            actorType: MessageActorType::System,
        );
    }

    /**
     * Queue an AI session (free-form) WhatsApp reply on an existing conversation.
     * Uses Cloud API text messages (not templates). Requires an open customer messaging window.
     */
    public function queueAiSessionReply(Conversation $conversation, string $body): Message
    {
        $this->assertDeliveryEnabled();

        if ($conversation->channel !== CommunicationChannel::WhatsApp) {
            throw ValidationException::withMessages([
                'channel' => 'AI session replies are only supported on WhatsApp conversations.',
            ]);
        }

        if ($conversation->mode === ConversationMode::Closed) {
            throw ValidationException::withMessages([
                'mode' => 'Cannot send AI replies on a closed conversation.',
            ]);
        }

        $conversation->loadMissing(['identity', 'contact']);
        $identity = $conversation->identity;
        if ($identity === null || ! $identity->is_active) {
            throw ValidationException::withMessages([
                'identity' => 'WhatsApp identity is missing or inactive.',
            ]);
        }

        $to = WhatsAppPhone::normalize((string) $identity->external_id);
        if ($to === null) {
            throw ValidationException::withMessages([
                'whatsapp' => 'Conversation identity is not a valid WhatsApp number.',
            ]);
        }

        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => 'AI reply body is required.',
            ]);
        }

        $message = DB::transaction(function () use ($conversation, $body, $to) {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => MessageDirection::Outbound,
                'channel' => CommunicationChannel::WhatsApp,
                'body' => $body,
                'subject' => 'AI reply',
                'template_key' => null,
                'document_id' => null,
                'status' => MessageStatus::Pending,
                'actor_type' => MessageActorType::Ai,
                'actor_user_id' => null,
                'external_message_id' => null,
                'occurred_at' => now(),
                'meta' => [
                    'to' => $to,
                    'delivery_kind' => 'session_text',
                    'ai' => true,
                ],
            ]);

            $conversation->last_message_at = $message->occurred_at;
            $conversation->save();

            return $message;
        });

        SendOutboundWhatsAppJob::dispatch($message->id);

        $this->auditLogger->record(
            event: 'whatsapp.ai_queued',
            description: 'AI WhatsApp session reply queued',
            auditable: $message,
            newValues: [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
            ],
        );

        return $message->refresh();
    }

    public function retry(Message $message, User $actor): Message
    {
        if ($message->channel !== CommunicationChannel::WhatsApp) {
            throw ValidationException::withMessages([
                'message' => 'Only WhatsApp messages can be retried through WhatsApp delivery.',
            ]);
        }

        if ($message->status->isTerminalSuccess()) {
            throw ValidationException::withMessages([
                'message' => 'This WhatsApp message was already submitted successfully. Queue a new send if another copy is required.',
            ]);
        }

        if (! $message->status->canRetryDelivery() && $message->status !== MessageStatus::Processing) {
            throw ValidationException::withMessages([
                'message' => 'This message cannot be retried in its current state.',
            ]);
        }

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

        SendOutboundWhatsAppJob::dispatch($message->id);

        $this->auditLogger->record(
            event: 'whatsapp.retry_queued',
            description: 'Outbound WhatsApp retry queued',
            auditable: $message,
            newValues: [
                'message_id' => $message->id,
                'document_id' => $message->document_id,
            ],
            actor: $actor,
        );

        return $message->refresh();
    }

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

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $deliveryKind = (string) ($meta['delivery_kind'] ?? 'template');

            if ($deliveryKind === 'session_text') {
                $to = WhatsAppPhone::normalize((string) ($meta['to'] ?? $locked->conversation?->identity?->external_id ?? ''));
                if ($to === null) {
                    throw ValidationException::withMessages([
                        'whatsapp' => 'Recipient WhatsApp number is missing or invalid.',
                    ]);
                }
                $result = $this->delivery->sendText(new WhatsAppTextPayload(
                    to: $to,
                    body: (string) $locked->body,
                    messageId: $locked->id,
                ));
            } elseif ($deliveryKind === 'document_pdf') {
                $result = $this->deliverDocumentPdf($locked, $meta);
            } else {
                $payload = $this->buildPayload($locked);
                $result = $this->delivery->sendTemplate($payload);
            }

            if (! $result->success) {
                $this->markFailed($locked, $result->failureReason ?? 'WhatsApp delivery failed.', $result->retryable);

                if (! $result->retryable) {
                    return;
                }

                throw new \RuntimeException('WhatsApp transient delivery failure');
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
                event: 'whatsapp.sent',
                description: 'Outbound WhatsApp message submitted to provider',
                auditable: $locked->fresh(),
                newValues: [
                    'message_id' => $locked->id,
                    'document_id' => $locked->document_id,
                    'provider_message_id' => $result->providerMessageId,
                ],
            );
        } catch (ValidationException $e) {
            $reason = collect($e->errors())->flatten()->first() ?: 'WhatsApp delivery validation failed.';
            $this->markFailed($locked, is_string($reason) ? $reason : 'WhatsApp delivery validation failed.', false);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'WhatsApp transient delivery failure') {
                throw $e;
            }
            report($e);
            $this->markFailed($locked, 'WhatsApp delivery failed unexpectedly. Please retry later.');
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            $this->markFailed($locked, 'WhatsApp delivery failed unexpectedly. Please retry later.');
            throw $e;
        }
    }

    /**
     * @param  Quote|Invoice|Payment  $documentable
     * @param  callable(): Document  $ensureDocument
     */
    private function queueDocumentWhatsApp(
        Quote|Invoice|Payment $documentable,
        ?Contact $contact,
        WhatsAppTemplateKey $templateKey,
        DocumentType $documentType,
        User $actor,
        callable $ensureDocument,
        MessageActorType $actorType = MessageActorType::Staff,
    ): Message {
        $this->assertDeliveryEnabled();
        $contact = $this->requireEligibleContact($contact);
        $to = $this->requireWhatsAppRecipient($contact);

        $document = $documentable->documents
            ->where('type', $documentType)
            ->sortByDesc('id')
            ->first();

        if ($document === null) {
            $document = $ensureDocument();
            $documentable->unsetRelation('documents');
        }

        if (! Storage::disk($document->disk)->exists($document->path)) {
            throw ValidationException::withMessages([
                'document' => 'The PDF file is missing from storage and cannot be sent on WhatsApp.',
            ]);
        }

        // Secure links remain for browser/admin use; WhatsApp delivery is the PDF attachment.
        $link = $this->documents->createSecureLink($document, $actor);
        $secureUrl = url('/d/'.$link['plain_token']);

        $identity = $this->conversations->findOrCreateIdentity(
            channel: CommunicationChannel::WhatsApp,
            externalId: $to,
            displayName: $contact->display_name,
            contact: $contact,
        );

        if ($identity->contact_id === null) {
            $this->conversations->linkIdentityToContact($identity, $contact);
            $identity->refresh();
        } elseif ((int) $identity->contact_id !== (int) $contact->id) {
            throw ValidationException::withMessages([
                'whatsapp' => 'This WhatsApp identity is already linked to a different contact.',
            ]);
        }

        if (! $identity->is_active) {
            throw ValidationException::withMessages([
                'whatsapp' => 'WhatsApp communication is disabled for this identity.',
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

        $customerName = $contact->display_name;
        $documentNumber = (string) $documentable->number;
        $businessName = Business::current()->name;
        $caption = $this->documentCaption($templateKey, $businessName, $documentNumber, $documentable);
        $body = $templateKey->label().' '.$documentNumber.' — PDF document attachment.';

        $message = DB::transaction(function () use (
            $conversation,
            $actor,
            $actorType,
            $body,
            $templateKey,
            $document,
            $to,
            $secureUrl,
            $documentNumber,
            $caption,
        ) {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => MessageDirection::Outbound,
                'channel' => CommunicationChannel::WhatsApp,
                'body' => $body,
                'subject' => $templateKey->label().' '.$documentNumber,
                'template_key' => $templateKey->value,
                'document_id' => $document->id,
                'status' => MessageStatus::Pending,
                'actor_type' => $actorType,
                'actor_user_id' => $actor->id,
                'external_message_id' => null,
                'occurred_at' => now(),
                'meta' => [
                    'to' => $to,
                    'secure_url' => $secureUrl,
                    'delivery_kind' => 'document_pdf',
                    'caption' => $caption,
                    'filename' => $document->filename,
                    'mime_type' => $document->mime_type ?: 'application/pdf',
                    'document_number' => $documentNumber,
                ],
            ]);

            $conversation->last_message_at = $message->occurred_at;
            $conversation->save();

            return $message;
        });

        SendOutboundWhatsAppJob::dispatch($message->id);

        $this->auditLogger->record(
            event: 'whatsapp.queued',
            description: 'Outbound document WhatsApp PDF attachment queued',
            auditable: $message,
            newValues: [
                'message_id' => $message->id,
                'document_id' => $document->id,
                'template' => $templateKey->value,
                'to' => $to,
                'delivery_kind' => 'document_pdf',
            ],
            actor: $actor,
        );

        return $message->refresh();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function deliverDocumentPdf(Message $message, array $meta): \App\Support\WhatsAppDeliveryResult
    {
        $to = WhatsAppPhone::normalize((string) ($meta['to'] ?? $message->conversation?->identity?->external_id ?? ''));
        if ($to === null) {
            throw ValidationException::withMessages([
                'whatsapp' => 'Recipient WhatsApp number is missing or invalid.',
            ]);
        }

        $document = $message->document;
        if ($document === null) {
            throw ValidationException::withMessages([
                'document' => 'Linked document is missing for this WhatsApp message.',
            ]);
        }

        if (! Storage::disk($document->disk)->exists($document->path)) {
            throw ValidationException::withMessages([
                'document' => 'The PDF file is missing from storage and cannot be sent on WhatsApp.',
            ]);
        }

        $absolute = Storage::disk($document->disk)->path($document->path);
        $mime = (string) ($meta['mime_type'] ?? $document->mime_type ?: 'application/pdf');
        $filename = (string) ($meta['filename'] ?? $document->filename);
        $caption = isset($meta['caption']) && is_string($meta['caption']) ? $meta['caption'] : null;

        $upload = $this->delivery->uploadMedia($absolute, $mime, $filename);
        if (! $upload->success || $upload->providerMessageId === null) {
            return $upload;
        }

        return $this->delivery->sendDocument(new WhatsAppDocumentPayload(
            to: $to,
            mediaId: $upload->providerMessageId,
            filename: $filename,
            mimeType: $mime,
            caption: $caption,
            messageId: $message->id,
        ));
    }

    private function documentCaption(
        WhatsAppTemplateKey $templateKey,
        string $businessName,
        string $documentNumber,
        Quote|Invoice|Payment $documentable,
    ): string {
        return match ($templateKey) {
            WhatsAppTemplateKey::Quote => "{$businessName}: Quote {$documentNumber} (PDF attached)",
            WhatsAppTemplateKey::Invoice => "{$businessName}: Invoice {$documentNumber} (PDF attached)",
            WhatsAppTemplateKey::InvoiceReminder => $documentable instanceof Invoice
                ? "{$businessName}: Invoice reminder {$documentNumber} — outstanding "
                    .number_format((float) $documentable->balance_due, 2).' '
                    .$documentable->currency_code.' (PDF attached)'
                : "{$businessName}: Invoice reminder {$documentNumber} (PDF attached)",
            WhatsAppTemplateKey::PaymentAcknowledgement => "{$businessName}: Payment acknowledgement {$documentNumber} (PDF attached)",
        };
    }

    private function buildPayload(Message $message): WhatsAppDeliveryPayload
    {
        $meta = is_array($message->meta) ? $message->meta : [];
        $to = WhatsAppPhone::normalize((string) ($meta['to'] ?? $message->conversation?->identity?->external_id ?? ''));

        if ($to === null) {
            throw ValidationException::withMessages([
                'whatsapp' => 'Recipient WhatsApp number is missing or invalid.',
            ]);
        }

        $templateKey = WhatsAppTemplateKey::from((string) $message->template_key);
        $templateName = (string) ($meta['template_name'] ?? $this->requireConfiguredTemplateName($templateKey));
        $language = (string) ($meta['template_language'] ?? config('adman.whatsapp.template_language', 'en'));
        $params = $meta['body_parameters'] ?? null;

        if (! is_array($params) || count($params) < 3) {
            throw ValidationException::withMessages([
                'whatsapp' => 'WhatsApp template parameters are incomplete for this message.',
            ]);
        }

        $secureUrl = (string) ($meta['secure_url'] ?? '');
        $document = $message->document;
        if ($document !== null && $secureUrl !== '' && ! $document->isAccessActive()) {
            $actor = $message->actorUser ?? User::query()->find($message->actor_user_id);
            if ($actor instanceof User) {
                $link = $this->documents->createSecureLink($document, $actor);
                $secureUrl = url('/d/'.$link['plain_token']);
                $params[2] = $secureUrl;
                $meta['secure_url'] = $secureUrl;
                $meta['body_parameters'] = $params;
                $message->meta = $meta;
                $message->save();
            }
        }

        return new WhatsAppDeliveryPayload(
            to: $to,
            templateName: $templateName,
            languageCode: $language,
            templateKey: $templateKey,
            bodyParameters: array_map('strval', array_values($params)),
            messageId: $message->id,
        );
    }

    /**
     * Meta-approved templates use three body parameters:
     * {{1}} customer name, {{2}} document number, {{3}} secure URL.
     * Invoice reminder templates should include outstanding/due wording in Meta copy;
     * outstanding balance is always accurate on the linked invoice PDF.
     *
     * @return list<string>
     */
    private function templateBodyParameters(
        WhatsAppTemplateKey $templateKey,
        Quote|Invoice|Payment $documentable,
        string $customerName,
        string $documentNumber,
        string $secureUrl,
    ): array {
        if ($templateKey === WhatsAppTemplateKey::InvoiceReminder && $documentable instanceof Invoice) {
            $outstanding = number_format((float) $documentable->balance_due, 2, '.', ',');
            $currency = (string) $documentable->currency_code;
            $due = $documentable->due_date?->toDateString() ?? '';

            // Still three Meta body slots; slot 2 carries factual invoice context for reminder templates.
            return [
                $customerName,
                trim($documentNumber.' · Outstanding '.$currency.' '.$outstanding.($due !== '' ? ' · Due '.$due : '')),
                $secureUrl,
            ];
        }

        return [$customerName, $documentNumber, $secureUrl];
    }

    private function conversationSubject(WhatsAppTemplateKey $templateKey, Quote|Invoice|Payment $documentable): string
    {
        return match ($templateKey) {
            WhatsAppTemplateKey::Quote => 'Quote '.$documentable->number,
            WhatsAppTemplateKey::Invoice, WhatsAppTemplateKey::InvoiceReminder => 'Invoice '.$documentable->number,
            WhatsAppTemplateKey::PaymentAcknowledgement => 'Payment '.$documentable->number,
        };
    }

    private function requireEligibleContact(?Contact $contact): Contact
    {
        if ($contact === null) {
            throw ValidationException::withMessages([
                'contact' => 'A customer contact is required to send WhatsApp messages.',
            ]);
        }

        if ($contact->isArchived()) {
            throw ValidationException::withMessages([
                'contact' => 'Cannot message an archived contact on WhatsApp.',
            ]);
        }

        if ($contact->status !== ContactStatus::Customer) {
            throw ValidationException::withMessages([
                'contact' => 'WhatsApp document delivery requires a Customer contact.',
            ]);
        }

        if (! $contact->whatsapp_opt_in) {
            throw ValidationException::withMessages([
                'whatsapp' => 'This customer has not opted in to WhatsApp business messages.',
            ]);
        }

        return $contact;
    }

    private function requireWhatsAppRecipient(Contact $contact): string
    {
        $to = WhatsAppPhone::fromContact($contact);

        if ($to === null) {
            throw ValidationException::withMessages([
                'whatsapp' => 'This customer does not have a valid WhatsApp number (whatsapp_id or phone with country code).',
            ]);
        }

        return $to;
    }

    private function requireConfiguredTemplateName(WhatsAppTemplateKey $templateKey): string
    {
        $name = (string) config('adman.whatsapp.templates.'.$templateKey->configKey(), '');

        if ($name === '') {
            throw ValidationException::withMessages([
                'whatsapp' => 'WhatsApp template name is not configured for '.$templateKey->label().'.',
            ]);
        }

        return $name;
    }

    private function assertDeliveryEnabled(): void
    {
        if (! (bool) config('adman.whatsapp.enabled', true)) {
            throw ValidationException::withMessages([
                'whatsapp' => 'Outbound WhatsApp is disabled for this deployment.',
            ]);
        }

        if (! Business::current()->outbound_whatsapp_enabled) {
            throw ValidationException::withMessages([
                'whatsapp' => 'Outbound WhatsApp is disabled in business settings.',
            ]);
        }
    }

    private function markFailed(Message $message, string $reason, bool $retryable = true): void
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
            event: 'whatsapp.failed',
            description: 'Outbound WhatsApp delivery failed',
            auditable: $message->fresh(),
            newValues: [
                'message_id' => $message->id,
                'document_id' => $message->document_id,
            ],
            meta: [
                'failure_reason' => $reason,
                'retryable' => $retryable,
            ],
        );
    }
}
