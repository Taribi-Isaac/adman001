<?php

namespace App\Http\Requests\Contacts;

use App\Enums\ContactStatus;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromoteContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::CONTACTS_PROMOTE) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in([
                    ContactStatus::Prospect->value,
                    ContactStatus::Customer->value,
                ]),
            ],
        ];
    }
}
