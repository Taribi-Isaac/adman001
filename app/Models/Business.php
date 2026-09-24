<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Single-organization business configuration for this deployment.
 *
 * @property int $id
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $registration_number
 * @property string|null $email
 * @property bool $outbound_email_enabled
 * @property string|null $email_reply_to
 * @property bool $outbound_whatsapp_enabled
 * @property bool $invoice_reminders_enabled
 * @property bool $ai_enabled
 * @property bool $ai_customer_responses_enabled
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $description
 * @property string|null $ai_support_instructions
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postal_code
 * @property string|null $country
 * @property string|null $logo_path
 * @property bool $tax_enabled
 * @property string|null $tax_name
 * @property string|null $tax_rate
 * @property string|null $tax_identification
 * @property string $currency_code
 * @property string $timezone
 * @property string|null $bank_name
 * @property string|null $bank_account_name
 * @property string|null $bank_account_number
 * @property string|null $payment_instructions
 * @property string|null $default_terms
 * @property string|null $invoice_number_prefix
 * @property string|null $quote_number_prefix
 * @property string|null $receipt_number_prefix
 * @property int $quote_next_sequence
 * @property int $invoice_next_sequence
 * @property int $receipt_next_sequence
 * @property int $default_payment_term_days
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Business extends Model
{
    protected $fillable = [
        'name',
        'legal_name',
        'registration_number',
        'email',
        'outbound_email_enabled',
        'email_reply_to',
        'outbound_whatsapp_enabled',
        'invoice_reminders_enabled',
        'ai_enabled',
        'ai_customer_responses_enabled',
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
        'logo_path',
        'tax_enabled',
        'tax_name',
        'tax_rate',
        'tax_identification',
        'currency_code',
        'timezone',
        'bank_name',
        'bank_account_name',
        'bank_account_number',
        'payment_instructions',
        'default_terms',
        'invoice_number_prefix',
        'quote_number_prefix',
        'receipt_number_prefix',
        'quote_next_sequence',
        'invoice_next_sequence',
        'receipt_next_sequence',
        'default_payment_term_days',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_enabled' => 'boolean',
            'outbound_email_enabled' => 'boolean',
            'outbound_whatsapp_enabled' => 'boolean',
            'invoice_reminders_enabled' => 'boolean',
            'ai_enabled' => 'boolean',
            'ai_customer_responses_enabled' => 'boolean',
            'tax_rate' => 'decimal:4',
            'quote_next_sequence' => 'integer',
            'invoice_next_sequence' => 'integer',
            'receipt_next_sequence' => 'integer',
            'default_payment_term_days' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * Resolve the single business record for this deployment.
     */
    public static function current(): self
    {
        $business = static::query()->first();

        if ($business === null) {
            $business = static::query()->create([
                'name' => config('app.name', 'ADMAN'),
                'currency_code' => 'NGN',
                'timezone' => config('app.timezone', 'UTC'),
                'tax_enabled' => false,
                'invoice_number_prefix' => 'INV-',
                'quote_number_prefix' => 'QT-',
                'receipt_number_prefix' => 'RCPT-',
                'quote_next_sequence' => 1,
                'invoice_next_sequence' => 1,
                'receipt_next_sequence' => 1,
                'default_payment_term_days' => 14,
            ]);
        }

        return $business;
    }
}
