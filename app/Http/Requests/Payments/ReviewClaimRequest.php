<?php

namespace App\Http\Requests\Payments;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class ReviewClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::PAYMENTS_CLAIMS_REVIEW) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reviewer_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('reviewer_notes') === '') {
            $this->merge(['reviewer_notes' => null]);
        }
    }
}
