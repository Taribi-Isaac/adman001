<?php

namespace App\Http\Requests\Settings;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::BUSINESS_UPDATE) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'outbound_email_enabled' => ['required', 'boolean'],
            'email_reply_to' => ['nullable', 'email', 'max:255'],
            'outbound_whatsapp_enabled' => ['required', 'boolean'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'ai_support_instructions' => ['nullable', 'string', 'max:5000'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:120'],
            'tax_enabled' => ['required', 'boolean'],
            'tax_name' => ['nullable', 'string', 'max:100'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_identification' => ['nullable', 'string', 'max:100'],
            'currency_code' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:100'],
            'payment_instructions' => ['nullable', 'string', 'max:5000'],
            'default_terms' => ['nullable', 'string', 'max:10000'],
            'invoice_number_prefix' => ['nullable', 'string', 'max:20'],
            'quote_number_prefix' => ['nullable', 'string', 'max:20'],
            'receipt_number_prefix' => ['nullable', 'string', 'max:20'],
            'default_payment_term_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $nullable = [
            'legal_name',
            'registration_number',
            'email',
            'email_reply_to',
            'phone',
            'website',
            'description',
            'ai_support_instructions',
            'address_line_1',
            'address_line_2',
            'city',
            'state',
            'postal_code',
            'country',
            'tax_name',
            'tax_rate',
            'tax_identification',
            'bank_name',
            'bank_account_name',
            'bank_account_number',
            'payment_instructions',
            'default_terms',
            'invoice_number_prefix',
            'quote_number_prefix',
            'receipt_number_prefix',
        ];

        $merged = [
            'tax_enabled' => $this->boolean('tax_enabled'),
            'outbound_email_enabled' => $this->boolean('outbound_email_enabled'),
            'outbound_whatsapp_enabled' => $this->boolean('outbound_whatsapp_enabled'),
            'currency_code' => strtoupper((string) $this->input('currency_code', 'NGN')),
        ];

        if ($this->filled('default_payment_term_days')) {
            $merged['default_payment_term_days'] = (int) $this->input('default_payment_term_days');
        } elseif (! $this->exists('default_payment_term_days')) {
            $merged['default_payment_term_days'] = 14;
        }

        foreach ($nullable as $field) {
            if ($this->input($field) === '') {
                $merged[$field] = null;
            }
        }

        $this->merge($merged);
    }
}
