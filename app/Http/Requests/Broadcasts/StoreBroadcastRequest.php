<?php

namespace App\Http\Requests\Broadcasts;

use App\Enums\BroadcastAudience;
use App\Enums\CommunicationChannel;
use App\Services\BroadcastService;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::BROADCASTS_MANAGE) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $email = $this->input('channel') === CommunicationChannel::Email->value;
        $selected = $this->input('audience_type') === BroadcastAudience::Selected->value;

        return [
            'name' => ['required', 'string', 'max:150'],
            'channel' => ['required', Rule::enum(CommunicationChannel::class)],
            'audience_type' => ['required', Rule::enum(BroadcastAudience::class)],
            'selected_contact_ids' => $selected
                ? ['required', 'array', 'min:1', 'max:'.BroadcastService::HARD_RECIPIENT_LIMIT]
                : ['nullable', 'array'],
            'selected_contact_ids.*' => ['integer', 'distinct', 'exists:contacts,id'],
            'subject' => $email ? ['required', 'string', 'max:200'] : ['nullable', 'string', 'max:200'],
            'body' => $email ? ['required', 'string', 'max:10000'] : ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'selected_contact_ids.required' => 'Select at least one contact.',
            'selected_contact_ids.max' => 'A broadcast can include at most '.BroadcastService::HARD_RECIPIENT_LIMIT.' selected contacts.',
            'subject.required' => 'An email broadcast needs a subject.',
            'body.required' => 'An email broadcast needs a message.',
        ];
    }
}
