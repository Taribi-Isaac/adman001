<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->string('logo_path')->nullable();
            $table->boolean('tax_enabled')->default(false);
            $table->string('tax_name')->nullable();
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->string('tax_identification')->nullable();
            $table->string('currency_code', 3)->default('NGN');
            $table->string('timezone')->default('UTC');
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->text('payment_instructions')->nullable();
            $table->text('default_terms')->nullable();
            $table->string('invoice_number_prefix')->nullable();
            $table->string('quote_number_prefix')->nullable();
            $table->string('receipt_number_prefix')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
