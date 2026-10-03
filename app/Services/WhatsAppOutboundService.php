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
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\User;
use App\Support\Permissions;
use App\Support\WhatsAppBroadcastTemplate;
use App\Support\WhatsAppDeliveryPayload;
use App\Support\WhatsAppDeliveryResult;
use App\Support\WhatsAppDocumentPayload;
use App\Support\WhatsAppPhone;
use App\Support\WhatsAppTextPayload;
use App\Support\WhatsAppTransactionalTemplate;
use App\WhatsApp\WhatsAppTextFormatter;
use Carbon\CarbonImmutable;
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
    /** Meta customer service window: free-form messages allowed for 24h after the user's last message. */
    public const CUSTOMER_SERVICE_WINDOW_HOURS = 24;

    /** Document message sent inside the customer service window. */
    public const KIND_DOCUMENT = 'document_pdf';

    /** Approved Utility template with the PDF as DOCUMENT header, used when the window is closed. */
    public const KIND_TEMPLATE = 'document_template';

    /** Approved Marketing template for a broadcast recipient (Task 038). Never free-form. */
    public const KIND_BROADCAST = 'broadcast_template';

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly DocumentService $documents,
        private readonly WhatsAppDeliveryAdapter $delivery,
        private readonly AuditLogger $auditLogger,
        private readonly BroadcastDeliveryGuard $broadcastGuard,
    ) {}

    /**
     * Queue one broadcast recipient's Marketing template message. Eligibility (including
     * broadcast consent) is decided by the caller through BroadcastEligibilityService.
     */
    public function queueBroadcastTemplate(
        Broadcast $broadcast,
        BroadcastRecipient $recipient,
        Contact $contact,
        WhatsAppBroadcastTemplate $template,
        User $actor,
    ): Message {
        $this->assertDeliveryEnabled();
        $to = $this->requireWhatsAppRecipient($contact);

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
            subject: 'Broadcast: '.$broadcast->name,
        );

        if ($conversation->isClosed()) {
            $conversation = $this->conversations->reopen($conversation, ConversationMode::Human);
        }

        $bodyParameters = $template->bodyParametersFor($contact->display_name, $broadcast->whatsapp_message);

        $body = 'Broadcast "'.$broadcast->name.'" — approved WhatsApp Marketing template '.$template->name.'.';
        if ($template->usesMessage()) {
            $body .= "\n\nCampaign message: ".WhatsAppBroadcastTemplate::cleanParameter((string) $broadcast->whatsapp_message);
        }

        $message = DB::transaction(function () use ($conversation, $actor, $broadcast, $recipient, $template, $to, $bodyParameters, $body) {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => MessageDirection::Outbound,
                'channel' => CommunicationChannel::WhatsApp,
                'body' => $body,
                'subject' => 'Broadcast: '.$broadcast->name,
                'template_key' => null,
                'document_id' => null,
                'status' => MessageStatus::Pending,
                'actor_type' => MessageActorType::Staff,
                'actor_user_id' => $actor->id,
                'external_message_id' => null,
                'occurred_at' => now(),
                'meta' => [
                    'to' => $to,
                    'delivery_kind' => self::KIND_BROADCAST,
                    'template_name' => $template->name,
                    'template_language' => $template->language,
                    'body_parameters' => $bodyParameters,
                    'broadcast_id' => $broadcast->id,
                    'broadcast_recipient_id' => $recipient->id,
                ],
            ]);

            $conversation->last_message_at = $message->occurred_at;
            $conversation->save();

            return $message;
        });

        SendOutboundWhatsAppJob::dispatch($message->id)->afterCommit();

        $this->auditLogger->record(
            event: 'whatsapp.queued',
            description: 'Broadcast WhatsApp Marketing template queued',
            auditable: $message,
            newValues: [
                'message_id' => $message->id,
                'to' => $to,
                'delivery_kind' => self::KIND_BROADCAST,
                'template_name' => $template->name,
                'broadcast_id' => $broadcast->id,
            ],
            actor: $actor,
        );

        return $message;
    }

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

        $message = $this->storeSessionText($conversation, $body, [
            'subject' => 'AI reply',
            'actor_type' => MessageActorType::Ai,
            'actor_user_id' => null,
            'meta' => [
                'to' => $to,
                'delivery_kind' => 'session_text',
                'ai' => true,
            ],
        ]);

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

    /**
     * Queue a free-text WhatsApp reply written by the staff member who owns the conversation.
     * Sent exactly as written (no AI formatting) through the same session-text delivery path.
     */
    public function queueStaffSessionReply(Conversation $conversation, User $staff, string $body): Message
    {
        $blocker = $this->staffReplyBlocker($conversation, $staff);
        if ($blocker !== null) {
            throw ValidationException::withMessages(['body' => $blocker]);
        }

        if (trim($body) === '') {
            throw ValidationException::withMessages([
                'body' => 'Reply text is required.',
            ]);
        }

        $to = (string) WhatsAppPhone::normalize((string) $conversation->identity->external_id);

        $message = $this->storeSessionText($conversation, trim($body), [
            'subject' => null,
            'actor_type' => MessageActorType::Staff,
            'actor_user_id' => $staff->id,
            'meta' => [
                'to' => $to,
                'delivery_kind' => 'session_text',
                'staff_reply' => true,
            ],
        ]);

        $this->auditLogger->record(
            event: 'whatsapp.staff_reply_queued',
            description: 'Staff WhatsApp reply queued',
            auditable: $message,
            newValues: [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
            ],
            actor: $staff,
        );

        return $message->refresh();
    }

    /**
     * Why the given staff member cannot send a free-text WhatsApp reply right now, or null if they can.
     */
    public function staffReplyBlocker(Conversation $conversation, User $staff): ?string
    {
        if ($conversation->channel !== CommunicationChannel::WhatsApp) {
            return 'WhatsApp replies are only available on WhatsApp conversations.';
        }

        if (! $staff->can(Permissions::MESSAGES_SEND)) {
            return 'You do not have permission to send WhatsApp messages.';
        }

        if (! (bool) config('adman.whatsapp.enabled', true) || ! Business::current()->outbound_whatsapp_enabled) {
            return 'Outbound WhatsApp is currently disabled.';
        }

        if ($conversation->isClosed()) {
            return 'This conversation is closed. Reopen it and take over to reply.';
        }

        if ($conversation->mode !== ConversationMode::Human) {
            return 'AI is handling this conversation. Take over to reply as staff.';
        }

        if ($conversation->assigned_user_id === null) {
            return 'Take over this conversation to reply.';
        }

        if ($conversation->assigned_user_id !== $staff->id) {
            $owner = $conversation->assignedUser()->value('name') ?? 'another staff member';

            return 'This conversation is being handled by '.$owner.'. Only the assigned staff member can reply.';
        }

        $identity = $conversation->identity;
        if ($identity === null || ! $identity->is_active || WhatsAppPhone::normalize((string) $identity->external_id) === null) {
            return 'This conversation has no valid WhatsApp number to reply to.';
        }

        if (! $this->customerServiceWindowOpen($conversation)) {
            return 'WhatsApp only allows free-text replies within 24 hours of the customer\'s last message. '
                .'The customer must message again before a normal reply can be sent (approved template messages are not available from this screen).';
        }

        return null;
    }

    /**
     * End of Meta's 24-hour customer service window, based on the customer's latest inbound
     * WhatsApp message stored for this identity (any of its conversations). Null if none.
     */
    public function customerServiceWindowExpiresAt(Conversation $conversation): ?CarbonImmutable
    {
        return $this->serviceWindowExpiresAtForIdentity($conversation->communication_identity_id);
    }

    public function customerServiceWindowOpen(Conversation $conversation): bool
    {
        return $this->serviceWindowOpenForIdentity($conversation->communication_identity_id);
    }

    private function serviceWindowExpiresAtForIdentity(?int $identityId): ?CarbonImmutable
    {
        if ($identityId === null) {
            return null;
        }

        $lastInbound = Message::query()
            ->where('direction', MessageDirection::Inbound->value)
            ->where('channel', CommunicationChannel::WhatsApp->value)
            ->whereIn('conversation_id', Conversation::query()
                ->where('communication_identity_id', $identityId)
                ->select('id'))
            ->max('occurred_at');

        if ($lastInbound === null) {
            return null;
        }

        return CarbonImmutable::parse($lastInbound)->addHours(self::CUSTOMER_SERVICE_WINDOW_HOURS);
    }

    private function serviceWindowOpenForIdentity(?int $identityId): bool
    {
        $expiresAt = $this->serviceWindowExpiresAtForIdentity($identityId);

        return $expiresAt !== null && $expiresAt->isFuture();
    }

    /**
     * @param  array{subject: string|null, actor_type: MessageActorType, actor_user_id: int|null, meta: array<string, mixed>}  $attributes
     */
    private function storeSessionText(Conversation $conversation, string $body, array $attributes): Message
    {
        $message = DB::transaction(function () use ($conversation, $body, $attributes) {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => MessageDirection::Outbound,
                'channel' => CommunicationChannel::WhatsApp,
                'body' => $body,
                'subject' => $attributes['subject'],
                'template_key' => null,
                'document_id' => null,
                'status' => MessageStatus::Pending,
                'actor_type' => $attributes['actor_type'],
                'actor_user_id' => $attributes['actor_user_id'],
                'external_message_id' => null,
                'occurred_at' => now(),
                'meta' => $attributes['meta'],
            ]);

            $conversation->last_message_at = $message->occurred_at;
            $conversation->save();

            return $message;
        });

        SendOutboundWhatsAppJob::dispatch($message->id);

        return $message;
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

        if (BroadcastDeliveryGuard::isBroadcastMessage($message)) {
            throw ValidationException::withMessages([
                'message' => 'Broadcast messages are never resent, so a failed broadcast message cannot be retried.',
            ]);
        }

        if (! $message->status->canRetryDelivery() && $message->status !== MessageStatus::Processing) {
            throw ValidationException::withMessages([
                'message' => 'This message cannot be retried in its current state.',
            ]);
        }

        $deliveryKind = is_array($message->meta) ? ($message->meta['delivery_kind'] ?? null) : null;
        $windowOpen = $this->serviceWindowOpenForIdentity($message->conversation?->communication_identity_id);

        if ($deliveryKind === 'session_text' && $message->conversation !== null && ! $windowOpen) {
            throw ValidationException::withMessages([
                'message' => 'The 24-hour WhatsApp customer service window has closed, so this free-text message can no longer be sent.',
            ]);
        }

        $templateKey = WhatsAppTemplateKey::tryFrom((string) $message->template_key);
        if (in_array($deliveryKind, [self::KIND_DOCUMENT, self::KIND_TEMPLATE], true)
            && $templateKey !== null
            && ! $windowOpen
            && WhatsAppTransactionalTemplate::forKey($templateKey) === null) {
            throw ValidationException::withMessages([
                'message' => WhatsAppTransactionalTemplate::unavailableReason($templateKey),
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
                // Only AI output is normalized; staff-written text is sent as entered.
                $body = $locked->actor_type === MessageActorType::Ai
                    ? WhatsAppTextFormatter::format((string) $locked->body)
                    : (string) $locked->body;
                $result = $this->delivery->sendText(new WhatsAppTextPayload(
                    to: $to,
                    body: $body,
                    messageId: $locked->id,
                ));
            } elseif ($deliveryKind === self::KIND_DOCUMENT || $deliveryKind === self::KIND_TEMPLATE) {
                $result = $this->deliverDocument($locked, $meta);
            } elseif ($deliveryKind === self::KIND_BROADCAST) {
                $result = $this->deliverBroadcast($locked, $meta);
            } else {
                throw ValidationException::withMessages([
                    'whatsapp' => 'Unsupported WhatsApp delivery type for this message. Queue a new send.',
                ]);
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

        // Inside the window a normal document message is valid; outside it Meta only accepts an approved template.
        $windowOpen = $this->serviceWindowOpenForIdentity($identity->id);
        $template = $windowOpen ? null : WhatsAppTransactionalTemplate::forKey($templateKey);

        if (! $windowOpen && $template === null) {
            $this->auditLogger->record(
                event: 'whatsapp.blocked',
                description: 'Transactional WhatsApp not sent: service window closed and no approved template enabled',
                auditable: $documentable,
                newValues: [
                    'template' => $templateKey->value,
                    'contact_id' => $contact->id,
                    'to' => $to,
                    'reason' => 'service_window_closed_template_unavailable',
                ],
                actor: $actor,
            );

            throw ValidationException::withMessages([
                'whatsapp' => WhatsAppTransactionalTemplate::unavailableReason($templateKey),
            ]);
        }

        $deliveryKind = $template === null ? self::KIND_DOCUMENT : self::KIND_TEMPLATE;

        // Secure links remain for browser use and as the template's link fallback; the PDF itself is attached.
        $link = $this->documents->createSecureLink($document, $actor);
        $secureUrl = url('/d/'.$link['plain_token']);

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
        $body = $templateKey->label().' '.$documentNumber.($deliveryKind === self::KIND_TEMPLATE
            ? ' — approved WhatsApp template with PDF attachment (customer service window closed).'
            : ' — PDF document attachment.');
        $bodyParameters = $this->templateBodyParameters($templateKey, $documentable, $customerName, $documentNumber, $secureUrl);

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
            $deliveryKind,
            $template,
            $bodyParameters,
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
                'meta' => array_filter([
                    'to' => $to,
                    'secure_url' => $secureUrl,
                    'delivery_kind' => $deliveryKind,
                    'caption' => $caption,
                    'filename' => $document->filename,
                    'mime_type' => $document->mime_type ?: 'application/pdf',
                    'document_number' => $documentNumber,
                    'body_parameters' => $bodyParameters,
                    'template_name' => $template?->name,
                    'template_language' => $template?->language,
                ], fn ($value) => $value !== null),
            ]);

            $conversation->last_message_at = $message->occurred_at;
            $conversation->save();

            return $message;
        });

        SendOutboundWhatsAppJob::dispatch($message->id);

        $this->auditLogger->record(
            event: 'whatsapp.queued',
            description: $deliveryKind === self::KIND_TEMPLATE
                ? 'Outbound document WhatsApp queued as approved template with PDF header'
                : 'Outbound document WhatsApp PDF attachment queued',
            auditable: $message,
            newValues: array_filter([
                'message_id' => $message->id,
                'document_id' => $document->id,
                'template' => $templateKey->value,
                'to' => $to,
                'delivery_kind' => $deliveryKind,
                'template_name' => $template?->name,
            ], fn ($value) => $value !== null),
            actor: $actor,
        );

        return $message->refresh();
    }

    /**
     * Send a transactional PDF. The mode is re-decided at send time because the window may
     * have opened or closed since the message was queued (delays, retries).
     *
     * @param  array<string, mixed>  $meta
     */
    private function deliverDocument(Message $message, array $meta): WhatsAppDeliveryResult
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

        $templateKey = WhatsAppTemplateKey::from((string) $message->template_key);
        $template = null;

        if (! $this->serviceWindowOpenForIdentity($message->conversation?->communication_identity_id)) {
            $template = WhatsAppTransactionalTemplate::forKey($templateKey);
            if ($template === null) {
                throw ValidationException::withMessages([
                    'whatsapp' => WhatsAppTransactionalTemplate::unavailableReason($templateKey),
                ]);
            }
        }

        $meta['delivery_kind'] = $template === null ? self::KIND_DOCUMENT : self::KIND_TEMPLATE;
        unset($meta['template_name'], $meta['template_language']);
        if ($template !== null) {
            $meta['template_name'] = $template->name;
            $meta['template_language'] = $template->language;
        }
        $message->meta = $meta;
        $message->save();

        $absolute = Storage::disk($document->disk)->path($document->path);
        $mime = (string) ($meta['mime_type'] ?? $document->mime_type ?: 'application/pdf');
        $filename = (string) ($meta['filename'] ?? $document->filename);
        $caption = isset($meta['caption']) && is_string($meta['caption']) ? $meta['caption'] : null;

        $upload = $this->delivery->uploadMedia($absolute, $mime, $filename);
        if (! $upload->success || $upload->providerMessageId === null) {
            return $upload;
        }

        if ($template !== null) {
            return $this->delivery->sendTemplate(
                $this->buildTemplatePayload($message, $template, $to, $upload->providerMessageId, $filename),
            );
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

    /**
     * Send a broadcast Marketing template (no header). Failures are never retried: a timeout
     * may still have reached the customer, and a duplicate marketing message is worse than a gap.
     *
     * @param  array<string, mixed>  $meta
     */
    private function deliverBroadcast(Message $message, array $meta): WhatsAppDeliveryResult
    {
        if (! BroadcastDeliveryGuard::isBroadcastMessage($message)) {
            throw ValidationException::withMessages([
                'whatsapp' => 'Broadcast details are missing for this message.',
            ]);
        }

        $blocked = $this->broadcastGuard->blockReason($message);
        if ($blocked !== null) {
            throw ValidationException::withMessages(['whatsapp' => $blocked]);
        }

        $to = WhatsAppPhone::normalize((string) ($meta['to'] ?? ''));
        $templateName = trim((string) ($meta['template_name'] ?? ''));
        $language = trim((string) ($meta['template_language'] ?? ''));
        if ($to === null || $templateName === '' || $language === '') {
            throw ValidationException::withMessages([
                'whatsapp' => 'Broadcast recipient number or template is missing for this message.',
            ]);
        }

        $params = array_map(function ($param): string {
            $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $param));

            return $clean === '' ? '-' : $clean;
        }, array_values((array) ($meta['body_parameters'] ?? [])));

        $result = $this->delivery->sendTemplate(new WhatsAppDeliveryPayload(
            to: $to,
            templateName: $templateName,
            languageCode: $language,
            templateKey: null,
            bodyParameters: $params,
            messageId: $message->id,
        ));

        return $result->success
            ? $result
            : WhatsAppDeliveryResult::failed($result->failureReason ?? 'WhatsApp delivery failed.', retryable: false);
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

    private function buildTemplatePayload(
        Message $message,
        WhatsAppTransactionalTemplate $template,
        string $to,
        string $mediaId,
        string $filename,
    ): WhatsAppDeliveryPayload {
        $meta = is_array($message->meta) ? $message->meta : [];
        $params = $meta['body_parameters'] ?? null;

        if (! is_array($params) || $params === []) {
            throw ValidationException::withMessages([
                'whatsapp' => 'WhatsApp template parameters are missing for this message. Queue a new send.',
            ]);
        }

        $params = array_values(array_map('strval', $params));

        $secureUrl = (string) ($meta['secure_url'] ?? '');
        $document = $message->document;
        if ($document !== null && $secureUrl !== '' && ! $document->isAccessActive()) {
            $actor = $message->actorUser ?? User::query()->find($message->actor_user_id);
            if ($actor instanceof User) {
                $link = $this->documents->createSecureLink($document, $actor);
                $freshUrl = url('/d/'.$link['plain_token']);
                $params = array_map(fn (string $param) => $param === $secureUrl ? $freshUrl : $param, $params);
                $meta['secure_url'] = $freshUrl;
                $meta['body_parameters'] = $params;
                $message->meta = $meta;
                $message->save();
            }
        }

        // Meta rejects empty values and newlines/tabs/long runs of spaces in template parameters.
        $params = array_map(function (string $param): string {
            $clean = trim((string) preg_replace('/\s+/u', ' ', $param));

            return $clean === '' ? '-' : $clean;
        }, $params);

        return new WhatsAppDeliveryPayload(
            to: $to,
            templateName: $template->name,
            languageCode: $template->language,
            templateKey: $template->key,
            bodyParameters: $params,
            messageId: $message->id,
            headerDocumentMediaId: $mediaId,
            headerDocumentFilename: $filename,
        );
    }

    /**
     * Body parameters for the approved Utility templates (header = the PDF document).
     * Quote / invoice / payment acknowledgement: {{1}} customer name, {{2}} document number, {{3}} secure link.
     * Invoice reminder: {{1}} customer name, {{2}} invoice number, {{3}} outstanding amount,
     * {{4}} due date, {{5}} secure link.
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
            return [
                $customerName,
                $documentNumber,
                trim($documentable->currency_code.' '.number_format((float) $documentable->balance_due, 2, '.', ',')),
                $documentable->due_date?->format('j M Y') ?? 'on receipt',
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
