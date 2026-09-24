<?php

namespace App\Http\Requests\Payments;

use App\Enums\PaymentMethod;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::PAYMENTS_RECORD) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'confirm_immediately' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->input('reference') === '') {
            $merged['reference'] = null;
        }

        if ($this->input('notes') === '') {
            $merged['notes'] = null;
        }

        if ($this->has('confirm_immediately')) {
            $merged['confirm_immediately'] = filter_var(
                $this->input('confirm_immediately'),
                FILTER_VALIDATE_BOOLEAN,
            );
        }

        $this->merge($merged);
    }
}
