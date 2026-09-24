<?php

namespace Database\Seeders;

use App\Models\Business;
use Illuminate\Database\Seeder;

class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        if (Business::query()->exists()) {
            return;
        }

        Business::query()->create([
            'name' => 'ADMAN Business',
            'currency_code' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'tax_enabled' => false,
            'tax_name' => 'VAT',
            'tax_rate' => '7.5000',
            'invoice_number_prefix' => 'INV-',
            'quote_number_prefix' => 'QT-',
            'receipt_number_prefix' => 'RCPT-',
        ]);
    }
}
