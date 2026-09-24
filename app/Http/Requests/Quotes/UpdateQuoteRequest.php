<?php

namespace App\Http\Requests\Quotes;

use App\Enums\DiscountType;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::QUOTES_UPDATE) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'expiry_date' => ['nullable', 'date'],
            'discount_type' => ['required', Rule::enum(DiscountType::class)],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'tax_enabled' => ['sometimes', 'boolean'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->input('expiry_date') === '') {
            $merged['expiry_date'] = null;
        }

        if ($this->input('notes') === '') {
            $merged['notes'] = null;
        }

        if ($this->input('terms') === '') {
            $merged['terms'] = null;
        }

        if ($this->has('tax_enabled')) {
            $merged['tax_enabled'] = filter_var($this->input('tax_enabled'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($this->input('discount_value') === '' || $this->input('discount_value') === null) {
            $merged['discount_value'] = '0';
        }

        $this->merge($merged);
    }
}
