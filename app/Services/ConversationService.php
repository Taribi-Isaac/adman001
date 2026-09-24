<?php

namespace App\Services;

use App\Enums\CommunicationChannel;
use App\Enums\ConversationMode;
use App\Enums\MessageActorType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\CommunicationIdentity;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConversationService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Find or create a communication identity for a channel + external id.
     */
    public function findOrCreateIdentity(
        CommunicationChannel $channel,
        string $externalId,
        ?string $displayName = null,
        ?Contact $contact = null,
    ): CommunicationIdentity {
        $externalId = trim($externalId);
        if ($channel === CommunicationChannel::Email) {
            $externalId = strtolower($externalId);
        }
        if ($channel === CommunicationChannel::WhatsApp) {
            $normalized = \App\Support\WhatsAppPhone::normalize($externalId);
            if ($normalized === null) {
                throw ValidationException::withMessages([
                    'external_id' => 'WhatsApp number must include a valid country code (digits only after normalization).',
                ]);
            }
            $externalId = $normalized;
        }

        /** @var CommunicationIdentity $identity */
        $identity = CommunicationIdentity::query()->firstOrCreate(
            [
                'channel' => $channel,
                'external_id' => $externalId,
            ],
            [
                'display_name' => $displayName,
                'contact_id' => $contact?->id,
                'is_active' => true,
            ],
        );

        if ($displayName !== null && $identity->display_name !== $displayName) {
            $identity->display_name = $displayName;
            $identity->save();
        }

        return $identity->refresh();
    }

    /**
     * Open or reuse an active (non-closed) conversation for an identity.
     */
    public function openConversation(
        CommunicationIdentity $identity,
        ConversationMode $mode = ConversationMode::Ai,
        ?string $subject = null,
    ): Conversation {
        $existing = Conversation::query()
            ->where('communication_identity_id', $identity->id)
            ->where('mode', '!=', ConversationMode::Closed->value)
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $conversation = Conversation::query()->create([
            'communication_identity_id' => $identity->id,
            'contact_id' => $identity->contact_id,
            'channel' => $identity->channel,
            'mode' => $mode,
            'subject' => $subject,
            'last_message_at' => null,
            'closed_at' => null,
        ]);

        $this->auditLogger->record(
            event: 'conversation.created',
            description: 'Conversation created',
            auditable: $conversation,
            newValues: [
                'channel' => $conversation->channel->value,
                'mode' => $conversation->mode->value,
                'communication_identity_id' => $identity->id,
                'contact_id' => $conversation->contact_id,
            ],
        );

        return $conversation;
    }

    /**
     * Link an identity (and its open conversations) to a Contact.
     * Does NOT change Contact lifecycle status.
     */
    public function linkIdentityToContact(
        CommunicationIdentity $identity,
        Contact $contact,
    ): CommunicationIdentity {
        if ($contact->isArchived()) {
            throw ValidationException::withMessages([
                'contact_id' => 'Cannot link to an archived contact.',
            ]);
        }

        $previousContactId = $identity->contact_id;
        $previousContactStatus = $contact->status->value;

        $identity->contact_id = $contact->id;
        if (! filled($identity->display_name)) {
            $identity->display_name = $contact->display_name;
        }
        $identity->save();

        Conversation::query()
            ->where('communication_identity_id', $identity->id)
            ->update(['contact_id' => $contact->id]);

        $this->auditLogger->record(
            event: 'communication_identity.linked',
            description: 'Communication identity linked to contact',
            auditable: $identity,
            oldValues: ['contact_id' => $previousContactId],
            newValues: [
                'contact_id' => $contact->id,
                'contact_status_unchanged' => $previousContactStatus,
            ],
            meta: [
                'contact_lifecycle_unchanged' => true,
            ],
        );

        // Reload contact to prove lifecycle unchanged for callers.
        $contact->refresh();

        return $identity->refresh();
    }

    public function unlinkIdentity(CommunicationIdentity $identity): CommunicationIdentity
    {
        $previousContactId = $identity->contact_id;

        $identity->contact_id = null;
        $identity->save();

        Conversation::query()
            ->where('communication_identity_id', $identity->id)
            ->update(['contact_id' => null]);

        $this->auditLogger->record(
            event: 'communication_identity.unlinked',
            description: 'Communication identity unlinked from contact',
            auditable: $identity,
            oldValues: ['contact_id' => $previousContactId],
            newValues: ['contact_id' => null],
        );

        return $identity->refresh();
    }

    public function takeOver(Conversation $conversation, User $user): Conversation
    {
        if ($conversation->isClosed()) {
            throw ValidationException::withMessages([
                'mode' => 'Closed conversations cannot be taken over. Reopen first.',
            ]);
        }

        $old = [
            'mode' => $conversation->mode->value,
            'assigned_user_id' => $conversation->assigned_user_id,
        ];

        $conversation->mode = ConversationMode::Human;
        $conversation->assigned_user_id = $user->id;
        $conversation->closed_at = null;
        $conversation->save();

        $this->auditLogger->record(
            event: 'conversation.taken_over',
            description: 'Conversation taken over by staff',
            auditable: $conversation,
            oldValues: $old,
            newValues: [
                'mode' => ConversationMode::Human->value,
                'assigned_user_id' => $user->id,
            ],
            actor: $user,
        );

        return $conversation->refresh();
    }

    public function returnToAi(Conversation $conversation): Conversation
    {
        if ($conversation->isClosed()) {
            throw ValidationException::withMessages([
                'mode' => 'Closed conversations cannot be returned to AI. Reopen first.',
            ]);
        }

        $old = [
            'mode' => $conversation->mode->value,
            'assigned_user_id' => $conversation->assigned_user_id,
        ];

        $conversation->mode = ConversationMode::Ai;
        $conversation->assigned_user_id = null;
        $conversation->save();

        $this->auditLogger->record(
            event: 'conversation.returned_to_ai',
            description: 'Conversation returned to AI control',
            auditable: $conversation,
            oldValues: $old,
            newValues: [
                'mode' => ConversationMode::Ai->value,
                'assigned_user_id' => null,
            ],
        );

        return $conversation->refresh();
    }

    public function escalateToHuman(Conversation $conversation, ?string $reason = null): Conversation
    {
        if ($conversation->isClosed()) {
            throw ValidationException::withMessages([
                'mode' => 'Closed conversations cannot be escalated. Reopen first.',
            ]);
        }

        if ($conversation->mode === ConversationMode::Human) {
            return $conversation;
        }

        $old = [
            'mode' => $conversation->mode->value,
            'assigned_user_id' => $conversation->assigned_user_id,
        ];

        $conversation->mode = ConversationMode::Human;
        // Leave assignment empty so any staff can pick up.
        $conversation->assigned_user_id = null;
        $conversation->closed_at = null;
        $conversation->save();

        $this->auditLogger->record(
            event: 'conversation.escalated_to_human',
            description: 'Conversation escalated to human'.($reason ? ': '.$reason : ''),
            auditable: $conversation,
            oldValues: $old,
            newValues: [
                'mode' => ConversationMode::Human->value,
                'assigned_user_id' => null,
                'reason' => $reason,
            ],
        );

        return $conversation->refresh();
    }

    public function close(Conversation $conversation): Conversation
    {
        if ($conversation->isClosed()) {
            throw ValidationException::withMessages([
                'mode' => 'Conversation is already closed.',
            ]);
        }

        $old = ['mode' => $conversation->mode->value];

        $conversation->mode = ConversationMode::Closed;
        $conversation->closed_at = now();
        $conversation->save();

        $this->auditLogger->record(
            event: 'conversation.closed',
            description: 'Conversation closed',
            auditable: $conversation,
            oldValues: $old,
            newValues: [
                'mode' => ConversationMode::Closed->value,
                'closed_at' => $conversation->closed_at?->toIso8601String(),
            ],
        );

        return $conversation->refresh();
    }

    public function reopen(Conversation $conversation, ConversationMode $mode = ConversationMode::Human): Conversation
    {
        if (! $conversation->isClosed()) {
            throw ValidationException::withMessages([
                'mode' => 'Conversation is not closed.',
            ]);
        }

        if ($mode === ConversationMode::Closed) {
            throw ValidationException::withMessages([
                'mode' => 'Reopen mode must be AI or Human.',
            ]);
        }

        $old = ['mode' => $conversation->mode->value];

        $conversation->mode = $mode;
        $conversation->closed_at = null;
        if ($mode === ConversationMode::Ai) {
            $conversation->assigned_user_id = null;
        }
        $conversation->save();

        $this->auditLogger->record(
            event: 'conversation.reopened',
            description: 'Conversation reopened',
            auditable: $conversation,
            oldValues: $old,
            newValues: [
                'mode' => $mode->value,
                'closed_at' => null,
            ],
        );

        return $conversation->refresh();
    }

    /**
     * Record an inbound message (e.g. from a future provider adapter or test seed).
     */
    public function recordInboundMessage(
        Conversation $conversation,
        string $body,
        ?string $externalMessageId = null,
        mixed $occurredAt = null,
    ): Message {
        return $this->storeMessage(
            conversation: $conversation,
            direction: MessageDirection::Inbound,
            body: $body,
            actorType: MessageActorType::External,
            actorUserId: null,
            status: MessageStatus::Recorded,
            externalMessageId: $externalMessageId,
            occurredAt: $occurredAt,
        );
    }

    /**
     * Compose an internal outbound message record.
     * This does NOT send via WhatsApp/email — status remains `recorded`.
     */
    public function composeInternalOutbound(
        Conversation $conversation,
        User $staff,
        string $body,
    ): Message {
        if ($conversation->isClosed()) {
            throw ValidationException::withMessages([
                'body' => 'Cannot compose messages on a closed conversation. Reopen first.',
            ]);
        }

        return $this->storeMessage(
            conversation: $conversation,
            direction: MessageDirection::Outbound,
            body: $body,
            actorType: MessageActorType::Staff,
            actorUserId: $staff->id,
            status: MessageStatus::Recorded,
            externalMessageId: null,
            occurredAt: now(),
        );
    }

    private function storeMessage(
        Conversation $conversation,
        MessageDirection $direction,
        string $body,
        MessageActorType $actorType,
        ?int $actorUserId,
        MessageStatus $status,
        ?string $externalMessageId,
        mixed $occurredAt,
    ): Message {
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => 'Message body is required.',
            ]);
        }

        return DB::transaction(function () use (
            $conversation,
            $direction,
            $body,
            $actorType,
            $actorUserId,
            $status,
            $externalMessageId,
            $occurredAt,
        ) {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => $direction,
                'channel' => $conversation->channel,
                'body' => $body,
                'status' => $status,
                'actor_type' => $actorType,
                'actor_user_id' => $actorUserId,
                'external_message_id' => $externalMessageId,
                'occurred_at' => $occurredAt ?? now(),
            ]);

            $conversation->last_message_at = $message->occurred_at;
            $conversation->save();

            return $message;
        });
    }
}
