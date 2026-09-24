<?php

namespace Database\Factories;

use App\Enums\CommunicationChannel;
use App\Models\CommunicationIdentity;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunicationIdentity>
 */
class CommunicationIdentityFactory extends Factory
{
    protected $model = CommunicationIdentity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $phone = fake()->unique()->numerify('+234##########');

        return [
            'channel' => CommunicationChannel::WhatsApp,
            'external_id' => $phone,
            'display_name' => fake()->optional()->name(),
            'contact_id' => null,
            'is_active' => true,
        ];
    }

    public function email(): static
    {
        return $this->state(fn () => [
            'channel' => CommunicationChannel::Email,
            'external_id' => fake()->unique()->safeEmail(),
        ]);
    }

    public function forContact(Contact $contact): static
    {
        return $this->state(fn () => [
            'contact_id' => $contact->id,
            'display_name' => $contact->display_name,
            'external_id' => $contact->phone
                ?? $contact->whatsapp_id
                ?? $contact->email
                ?? fake()->unique()->numerify('+234##########'),
        ]);
    }
}
