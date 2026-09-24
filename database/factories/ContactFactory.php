<?php

namespace Database\Factories;

use App\Enums\ContactStatus;
use App\Enums\ContactType;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'type' => ContactType::Individual,
            'status' => ContactStatus::Unknown,
            'display_name' => trim("{$firstName} {$lastName}"),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'organization_name' => null,
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('+234##########'),
            'whatsapp_id' => null,
            'address_line_1' => null,
            'address_line_2' => null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
            'country' => null,
            'notes' => null,
            'archived_at' => null,
        ];
    }

    public function unknown(): static
    {
        return $this->state(fn () => ['status' => ContactStatus::Unknown]);
    }

    public function prospect(): static
    {
        return $this->state(fn () => ['status' => ContactStatus::Prospect]);
    }

    public function customer(): static
    {
        return $this->state(fn () => ['status' => ContactStatus::Customer]);
    }

    public function organization(): static
    {
        return $this->state(function () {
            $name = fake()->company();

            return [
                'type' => ContactType::Organization,
                'organization_name' => $name,
                'display_name' => $name,
                'first_name' => null,
                'last_name' => null,
            ];
        });
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }

    public function minimalUnknown(?string $phone = null): static
    {
        $phone ??= fake()->unique()->numerify('+234##########');

        return $this->state(fn () => [
            'type' => ContactType::Individual,
            'status' => ContactStatus::Unknown,
            'display_name' => $phone,
            'first_name' => null,
            'last_name' => null,
            'organization_name' => null,
            'email' => null,
            'phone' => $phone,
            'whatsapp_id' => $phone,
        ]);
    }
}
