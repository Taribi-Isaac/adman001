<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Enums\InvoiceLifecycleStatus;
use App\Enums\InvoicePaymentStatus;
use App\Models\Contact;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = '100.00';

        return [
            'number' => 'INV-'.fake()->unique()->numerify('####'),
            'contact_id' => Contact::factory()->customer(),
            'quote_id' => null,
            'lifecycle_status' => InvoiceLifecycleStatus::Draft,
            'payment_status' => InvoicePaymentStatus::Unpaid,
            'issue_date' => null,
            'due_date' => now()->addDays(14)->toDateString(),
            'currency_code' => 'NGN',
            'discount_type' => DiscountType::None,
            'discount_value' => '0.0000',
            'discount_amount' => '0.00',
            'tax_enabled' => false,
            'tax_name' => null,
            'tax_rate' => '0.0000',
            'tax_amount' => '0.00',
            'subtotal' => $total,
            'taxable_subtotal' => $total,
            'total' => $total,
            'amount_paid' => '0.00',
            'balance_due' => $total,
            'notes' => null,
            'terms' => null,
            'business_snapshot' => null,
            'customer_snapshot' => null,
            'issued_at' => null,
            'cancelled_at' => null,
            'created_by' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Invoice $invoice): void {
            if ($invoice->items()->exists()) {
                return;
            }

            $invoice->items()->create([
                'position' => 0,
                'description' => 'Service',
                'quantity' => '1.0000',
                'unit' => null,
                'unit_price' => $invoice->total,
                'line_subtotal' => $invoice->total,
            ]);
        });
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'lifecycle_status' => InvoiceLifecycleStatus::Draft,
            'payment_status' => InvoicePaymentStatus::Unpaid,
            'issue_date' => null,
            'issued_at' => null,
        ]);
    }

    public function issued(): static
    {
        return $this->state(fn () => [
            'lifecycle_status' => InvoiceLifecycleStatus::Issued,
            'payment_status' => InvoicePaymentStatus::Unpaid,
            'issue_date' => now()->toDateString(),
            'issued_at' => now(),
            'amount_paid' => '0.00',
            'business_snapshot' => ['name' => 'Test Business'],
            'customer_snapshot' => ['display_name' => 'Test Customer'],
        ]);
    }
}
