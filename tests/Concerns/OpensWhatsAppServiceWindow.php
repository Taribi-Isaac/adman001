<?php

namespace Tests\Concerns;

use App\Enums\CommunicationChannel;
use App\Enums\ConversationMode;
use App\Models\Contact;
use App\Models\Conversation;
use App\Services\ConversationService;
use App\Support\WhatsAppPhone;
use DateTimeInterface;

trait OpensWhatsAppServiceWindow
{
    /**
     * Record an inbound WhatsApp message from the contact. At the default time (1 hour ago)
     * the 24-hour customer service window is open; pass an older time to model a closed window.
     */
    protected function recordWhatsAppInboundFrom(Contact $contact, ?DateTimeInterface $at = null): Conversation
    {
        $service = app(ConversationService::class);
        $identity = $service->findOrCreateIdentity(
            CommunicationChannel::WhatsApp,
            (string) WhatsAppPhone::fromContact($contact),
            $contact->display_name,
            $contact,
        );
        $conversation = $service->openConversation($identity, ConversationMode::Human);
        $service->recordInboundMessage($conversation, 'Hello', occurredAt: $at ?? now()->subHour());

        return $conversation;
    }
}
