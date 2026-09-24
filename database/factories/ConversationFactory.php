<?php

namespace Database\Factories;

use App\Enums\CommunicationChannel;
use App\Enums\ConversationMode;
use App\Models\CommunicationIdentity;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'communication_identity_id' => CommunicationIdentity::factory(),
            'contact_id' => null,
            'channel' => CommunicationChannel::WhatsApp,
            'mode' => ConversationMode::Ai,
            'assigned_user_id' => null,
            'subject' => null,
            'last_message_at' => now(),
            'closed_at' => null,
        ];
    }

    public function human(): static
    {
        return $this->state(fn () => [
            'mode' => ConversationMode::Human,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'mode' => ConversationMode::Closed,
            'closed_at' => now(),
        ]);
    }

    public function forIdentity(CommunicationIdentity $identity): static
    {
        return $this->state(fn () => [
            'communication_identity_id' => $identity->id,
            'contact_id' => $identity->contact_id,
            'channel' => $identity->channel,
        ]);
    }
}
