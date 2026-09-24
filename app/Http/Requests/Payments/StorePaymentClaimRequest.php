<?php

namespace App\Http\Requests\Payments;

use App\Enums\PaymentMethod;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::PAYMENTS_CLAIMS_CREATE) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'claimed_amount' => ['required', 'numeric', 'gt:0'],
            'claimed_payment_date' => ['nullable', 'date'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'customer_reference' => ['nullable', 'string', 'max:255'],
            'supporting_info' => ['nullable', 'string', 'max:5000'],
            'source_channel' => ['required', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        foreach (['claimed_payment_date', 'customer_reference', 'supporting_info', 'payment_method'] as $field) {
            if ($this->input($field) === '') {
                $merged[$field] = null;
            }
        }

        if (! $this->filled('source_channel')) {
            $merged['source_channel'] = 'staff';
        }

        $this->merge($merged);
    }
}
