<?php

namespace App\Http\Requests\Conversations;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class LinkContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::CONVERSATIONS_LINK_CONTACT) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
        ];
    }
}
