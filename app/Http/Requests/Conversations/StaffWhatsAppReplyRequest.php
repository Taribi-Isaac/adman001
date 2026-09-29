<?php

namespace App\Http\Requests\Conversations;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class StaffWhatsAppReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::MESSAGES_SEND) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // WhatsApp Cloud API text body limit.
            'body' => ['required', 'string', 'max:4096'],
        ];
    }
}
