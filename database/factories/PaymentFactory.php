<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'RCPT-'.fake()->unique()->numerify('#####'),
            'invoice_id' => Invoice::factory()->issued(),
            'contact_id' => fn (array $attrs) => Invoice::query()->find($attrs['invoice_id'])?->contact_id,
            'amount' => '50.00',
            'currency_code' => 'NGN',
            'payment_method' => PaymentMethod::BankTransfer,
            'payment_date' => now()->toDateString(),
            'reference' => fake()->optional()->bothify('TRX-####'),
            'notes' => null,
            'status' => PaymentStatus::Pending,
            'recorded_by' => null,
            'confirmed_by' => null,
            'confirmed_at' => null,
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_notes' => null,
            'business_snapshot' => null,
            'customer_snapshot' => null,
            'invoice_snapshot' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Payment $payment): void {
            if ($payment->contact_id === null && $payment->invoice_id) {
                $payment->contact_id = Invoice::query()->find($payment->invoice_id)?->contact_id;
            }
        });
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Confirmed,
            'confirmed_at' => now(),
            'business_snapshot' => ['name' => 'Test Business'],
            'customer_snapshot' => ['display_name' => 'Test Customer'],
            'invoice_snapshot' => [
                'total' => '100.00',
                'amount_paid_after' => '50.00',
                'balance_due_after' => '50.00',
            ],
        ]);
    }
}
