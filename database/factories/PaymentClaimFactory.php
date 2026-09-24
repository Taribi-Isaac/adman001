<?php

namespace Database\Factories;

use App\Enums\PaymentClaimStatus;
use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\PaymentClaim;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentClaim>
 */
class PaymentClaimFactory extends Factory
{
    protected $model = PaymentClaim::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory()->issued(),
            'contact_id' => fn (array $attrs) => Invoice::query()->find($attrs['invoice_id'])?->contact_id,
            'claimed_amount' => '50.00',
            'claimed_payment_date' => now()->toDateString(),
            'payment_method' => PaymentMethod::BankTransfer,
            'customer_reference' => fake()->optional()->bothify('REF-####'),
            'supporting_info' => null,
            'source_channel' => 'staff',
            'status' => PaymentClaimStatus::PendingVerification,
            'payment_id' => null,
            'created_by' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'reviewer_notes' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (PaymentClaim $claim): void {
            if ($claim->contact_id === null && $claim->invoice_id) {
                $claim->contact_id = Invoice::query()->find($claim->invoice_id)?->contact_id;
            }
        });
    }
}
