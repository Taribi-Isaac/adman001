<?php

namespace App\Services;

use App\Enums\ContactStatus;
use App\Enums\ContactType;
use App\Models\Contact;
use Illuminate\Validation\ValidationException;

class ContactService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Contact
    {
        $payload = $this->preparePayload($data, defaultStatus: ContactStatus::Unknown);
        $this->assertNoDuplicateIdentifiers($payload);

        $contact = Contact::query()->create($payload);

        $this->auditLogger->record(
            event: 'contact.created',
            description: 'Contact created',
            auditable: $contact,
            newValues: $contact->only([
                'type', 'status', 'display_name', 'email', 'phone', 'whatsapp_id',
            ]),
        );

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Contact $contact, array $data): Contact
    {
        if ($contact->isArchived()) {
            throw ValidationException::withMessages([
                'contact' => 'Archived contacts cannot be edited. Restore the contact first.',
            ]);
        }

        $payload = $this->preparePayload($data, defaultStatus: $contact->status);
        // Status changes must go through promote actions.
        unset($payload['status']);

        $this->assertNoDuplicateIdentifiers($payload, ignoreContactId: $contact->id);

        $old = $contact->only(array_keys($payload));
        $contact->fill($payload);
        $contact->save();

        $this->auditLogger->record(
            event: 'contact.updated',
            description: 'Contact updated',
            auditable: $contact,
            oldValues: $old,
            newValues: $contact->only(array_keys($payload)),
        );

        return $contact->refresh();
    }

    public function promote(Contact $contact, ContactStatus $target): Contact
    {
        if ($contact->isArchived()) {
            throw ValidationException::withMessages([
                'status' => 'Archived contacts cannot be promoted.',
            ]);
        }

        if (! $contact->status->canPromoteTo($target)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'Cannot promote a %s contact to %s.',
                    $contact->status->label(),
                    $target->label(),
                ),
            ]);
        }

        $oldStatus = $contact->status;
        $contact->status = $target;
        $contact->save();

        $this->auditLogger->record(
            event: 'contact.promoted',
            description: sprintf('Contact promoted from %s to %s', $oldStatus->label(), $target->label()),
            auditable: $contact,
            oldValues: ['status' => $oldStatus->value],
            newValues: ['status' => $target->value],
        );

        return $contact->refresh();
    }

    public function archive(Contact $contact): Contact
    {
        if ($contact->isArchived()) {
            throw ValidationException::withMessages([
                'contact' => 'Contact is already archived.',
            ]);
        }

        $contact->archived_at = now();
        $contact->save();

        $this->auditLogger->record(
            event: 'contact.archived',
            description: 'Contact archived',
            auditable: $contact,
            newValues: [
                'archived_at' => $contact->archived_at?->toIso8601String(),
                'status' => $contact->status->value,
            ],
        );

        return $contact->refresh();
    }

    public function restore(Contact $contact): Contact
    {
        if (! $contact->isArchived()) {
            throw ValidationException::withMessages([
                'contact' => 'Contact is not archived.',
            ]);
        }

        $this->assertNoDuplicateIdentifiers([
            'email' => $contact->email,
            'phone' => $contact->phone,
            'whatsapp_id' => $contact->whatsapp_id,
        ], ignoreContactId: $contact->id);

        $contact->archived_at = null;
        $contact->save();

        $this->auditLogger->record(
            event: 'contact.restored',
            description: 'Contact restored from archive',
            auditable: $contact,
            newValues: ['archived_at' => null],
        );

        return $contact->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function preparePayload(array $data, ContactStatus $defaultStatus): array
    {
        $type = $data['type'] instanceof ContactType
            ? $data['type']
            : ContactType::from((string) $data['type']);

        $status = isset($data['status'])
            ? ($data['status'] instanceof ContactStatus
                ? $data['status']
                : ContactStatus::from((string) $data['status']))
            : $defaultStatus;

        $firstName = $this->nullableString($data['first_name'] ?? null);
        $lastName = $this->nullableString($data['last_name'] ?? null);
        $organizationName = $this->nullableString($data['organization_name'] ?? null);
        $email = $this->nullableString($data['email'] ?? null);
        $phone = $this->nullableString($data['phone'] ?? null);
        $whatsappId = $this->nullableString($data['whatsapp_id'] ?? null);

        if ($email !== null) {
            $email = strtolower($email);
        }

        $displayName = Contact::deriveDisplayName(
            $type,
            $firstName,
            $lastName,
            $organizationName,
            $email,
            $phone,
            $whatsappId,
        );

        $payload = [
            'type' => $type,
            'status' => $status,
            'display_name' => $displayName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'organization_name' => $organizationName,
            'email' => $email,
            'phone' => $phone,
            'whatsapp_id' => $whatsappId,
            'address_line_1' => $this->nullableString($data['address_line_1'] ?? null),
            'address_line_2' => $this->nullableString($data['address_line_2'] ?? null),
            'city' => $this->nullableString($data['city'] ?? null),
            'state' => $this->nullableString($data['state'] ?? null),
            'postal_code' => $this->nullableString($data['postal_code'] ?? null),
            'country' => $this->nullableString($data['country'] ?? null),
            'notes' => $this->nullableString($data['notes'] ?? null),
        ];

        if (array_key_exists('whatsapp_opt_in', $data)) {
            $payload['whatsapp_opt_in'] = (bool) $data['whatsapp_opt_in'];
        }

        if (array_key_exists('reminder_channel', $data) && $data['reminder_channel'] !== null && $data['reminder_channel'] !== '') {
            $payload['reminder_channel'] = $data['reminder_channel'] instanceof \App\Enums\ReminderChannelPreference
                ? $data['reminder_channel']
                : \App\Enums\ReminderChannelPreference::from((string) $data['reminder_channel']);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertNoDuplicateIdentifiers(array $payload, ?int $ignoreContactId = null): void
    {
        $checks = [
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'whatsapp_id' => $payload['whatsapp_id'] ?? null,
        ];

        $errors = [];

        foreach ($checks as $field => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $query = Contact::query()->active()->where($field, $value);

            if ($ignoreContactId !== null) {
                $query->whereKeyNot($ignoreContactId);
            }

            /** @var Contact|null $existing */
            $existing = $query->first();

            if ($existing !== null) {
                $errors[$field] = sprintf(
                    'Another active contact already uses this %s (%s).',
                    str_replace('_', ' ', $field),
                    $existing->display_name,
                );
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
