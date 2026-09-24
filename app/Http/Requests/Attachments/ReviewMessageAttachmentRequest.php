<?php

namespace App\Http\Requests\Attachments;

use App\Enums\AttachmentReviewStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewMessageAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'review_status' => ['required', 'string', Rule::in(AttachmentReviewStatus::values())],
            'reviewer_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);
        if (! is_array($data)) {
            return $data;
        }

        if (array_key_exists('reviewer_notes', $data) && $data['reviewer_notes'] === '') {
            $data['reviewer_notes'] = null;
        }

        return $data;
    }
}
