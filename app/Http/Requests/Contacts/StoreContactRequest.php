<?php

namespace App\Http\Requests\Contacts;

use App\Enums\ContactStatus;
use App\Enums\ContactType;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::CONTACTS_CREATE) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ContactType::class)],
            'status' => ['sometimes', Rule::enum(ContactStatus::class)],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'organization_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'whatsapp_id' => ['nullable', 'string', 'max:100'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = ContactType::from((string) $this->input('type'));
            $hasIdentity = filled($this->input('email'))
                || filled($this->input('phone'))
                || filled($this->input('whatsapp_id'))
                || filled($this->input('first_name'))
                || filled($this->input('last_name'))
                || filled($this->input('organization_name'));

            if (! $hasIdentity) {
                $validator->errors()->add(
                    'phone',
                    'Provide at least one identifier: name, organization, email, phone, or WhatsApp ID.',
                );
            }

            if ($type === ContactType::Organization && blank($this->input('organization_name'))) {
                // Organization without a name is allowed only as Unknown with another identifier.
                if (! filled($this->input('email')) && ! filled($this->input('phone')) && ! filled($this->input('whatsapp_id'))) {
                    $validator->errors()->add(
                        'organization_name',
                        'Organization contacts need an organization name or a contact identifier.',
                    );
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $nullable = [
            'first_name', 'last_name', 'organization_name', 'email', 'phone', 'whatsapp_id',
            'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country', 'notes',
        ];

        $merged = [];
        foreach ($nullable as $field) {
            if ($this->input($field) === '') {
                $merged[$field] = null;
            }
        }

        if (! $this->filled('status')) {
            $merged['status'] = ContactStatus::Unknown->value;
        }

        $this->merge($merged);
    }
}
