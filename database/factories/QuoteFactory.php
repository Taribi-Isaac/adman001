<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Enums\QuoteStatus;
use App\Models\Contact;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = '100.00';
        $taxAmount = '0.00';
        $total = '100.00';

        return [
            'number' => 'QT-'.fake()->unique()->numerify('####'),
            'contact_id' => Contact::factory()->customer(),
            'status' => QuoteStatus::Draft,
            'issue_date' => null,
            'expiry_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'NGN',
            'discount_type' => DiscountType::None,
            'discount_value' => '0.0000',
            'discount_amount' => '0.00',
            'tax_enabled' => false,
            'tax_name' => null,
            'tax_rate' => '0.0000',
            'tax_amount' => $taxAmount,
            'subtotal' => $subtotal,
            'taxable_subtotal' => $subtotal,
            'total' => $total,
            'notes' => null,
            'terms' => null,
            'business_snapshot' => null,
            'customer_snapshot' => null,
            'issued_at' => null,
            'accepted_at' => null,
            'rejected_at' => null,
            'cancelled_at' => null,
            'created_by' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Quote $quote): void {
            if ($quote->items()->exists()) {
                return;
            }

            $quote->items()->create([
                'position' => 0,
                'description' => 'Service',
                'quantity' => '1.0000',
                'unit' => null,
                'unit_price' => $quote->subtotal,
                'line_subtotal' => $quote->subtotal,
            ]);
        });
    }

    public function issued(): static
    {
        return $this->state(fn () => [
            'status' => QuoteStatus::Issued,
            'issue_date' => now()->toDateString(),
            'issued_at' => now(),
            'business_snapshot' => ['name' => 'Test Business'],
            'customer_snapshot' => ['display_name' => 'Test Customer'],
        ]);
    }

    public function accepted(): static
    {
        return $this->issued()->state(fn () => [
            'status' => QuoteStatus::Accepted,
            'accepted_at' => now(),
        ]);
    }
}
