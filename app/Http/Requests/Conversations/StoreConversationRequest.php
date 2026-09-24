<?php

namespace App\Http\Requests\Conversations;

use App\Enums\CommunicationChannel;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::CONVERSATIONS_MANAGE) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::enum(CommunicationChannel::class)],
            'external_id' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'subject' => ['nullable', 'string', 'max:255'],
            'initial_message' => ['nullable', 'string', 'max:10000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        foreach (['display_name', 'subject', 'initial_message'] as $field) {
            if ($this->input($field) === '') {
                $merged[$field] = null;
            }
        }

        if ($this->input('contact_id') === '' || $this->input('contact_id') === null) {
            $merged['contact_id'] = null;
        }

        if ($this->filled('external_id')) {
            $merged['external_id'] = trim((string) $this->input('external_id'));
        }

        $this->merge($merged);
    }
}
