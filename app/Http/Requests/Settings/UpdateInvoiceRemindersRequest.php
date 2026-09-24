<?php

namespace App\Http\Requests\Settings;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRemindersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::AUTOMATION_REMINDERS_MANAGE) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.id' => ['nullable', 'integer'],
            'rules.*.offset_days' => ['required', 'integer'],
            'rules.*.is_enabled' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $rules = $this->input('rules', []);
        if (is_array($rules)) {
            $normalized = [];
            foreach ($rules as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $normalized[] = [
                    'id' => $row['id'] ?? null,
                    'offset_days' => (int) ($row['offset_days'] ?? 0),
                    'is_enabled' => filter_var($row['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
            }
            $this->merge([
                'enabled' => $this->boolean('enabled'),
                'rules' => $normalized,
            ]);
        }
    }
}
