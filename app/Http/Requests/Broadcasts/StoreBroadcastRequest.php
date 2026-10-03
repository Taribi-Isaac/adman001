<?php

namespace App\Http\Requests\Broadcasts;

use App\Enums\BroadcastAudience;
use App\Enums\CommunicationChannel;
use App\Services\BroadcastService;
use App\Support\Permissions;
use App\Support\WhatsAppBroadcastTemplate;
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
        $whatsapp = $this->input('channel') === CommunicationChannel::WhatsApp->value;
        $selected = $this->input('audience_type') === BroadcastAudience::Selected->value;

        $messageRules = ['string', 'max:'.WhatsAppBroadcastTemplate::MESSAGE_MAX_LENGTH, 'not_regex:/\{\{|\}\}/'];
        $template = $whatsapp ? WhatsAppBroadcastTemplate::configured() : null;
        $whatsappMessage = match (true) {
            $template?->usesMessage() === true => ['required', ...$messageRules],
            $template !== null => ['prohibited'],
            default => ['nullable', ...$messageRules],
        };

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
            'whatsapp_message' => $whatsapp ? $whatsappMessage : ['nullable', 'string', 'max:'.WhatsAppBroadcastTemplate::MESSAGE_MAX_LENGTH],
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
            'whatsapp_message.required' => 'A WhatsApp broadcast needs a campaign message.',
            'whatsapp_message.max' => 'The campaign message can be at most '.WhatsAppBroadcastTemplate::MESSAGE_MAX_LENGTH.' characters.',
            'whatsapp_message.not_regex' => 'The campaign message cannot contain {{ or }}. Write plain text; the customer name is added automatically.',
            'whatsapp_message.prohibited' => 'The configured WhatsApp template has fixed wording and cannot include a campaign message.',
        ];
    }
}
