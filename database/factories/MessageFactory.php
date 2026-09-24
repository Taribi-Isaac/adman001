<?php

namespace Database\Factories;

use App\Enums\CommunicationChannel;
use App\Enums\MessageActorType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'direction' => MessageDirection::Inbound,
            'channel' => CommunicationChannel::WhatsApp,
            'body' => fake()->sentence(),
            'status' => MessageStatus::Recorded,
            'actor_type' => MessageActorType::External,
            'actor_user_id' => null,
            'external_message_id' => null,
            'occurred_at' => now(),
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn () => [
            'direction' => MessageDirection::Inbound,
            'actor_type' => MessageActorType::External,
            'actor_user_id' => null,
        ]);
    }

    public function outboundStaff(int $userId): static
    {
        return $this->state(fn () => [
            'direction' => MessageDirection::Outbound,
            'actor_type' => MessageActorType::Staff,
            'actor_user_id' => $userId,
            'status' => MessageStatus::Recorded,
        ]);
    }
}
