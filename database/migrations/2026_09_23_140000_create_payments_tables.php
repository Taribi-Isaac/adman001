<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedBigInteger('receipt_next_sequence')->default(1)->after('invoice_next_sequence');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('currency_code', 3);
            $table->string('payment_method', 32);
            $table->date('payment_date');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_notes')->nullable();
            $table->json('business_snapshot')->nullable();
            $table->json('customer_snapshot')->nullable();
            $table->json('invoice_snapshot')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
            $table->index('contact_id');
            $table->index('payment_date');
            $table->index('payment_method');
            $table->index('reference');
        });

        Schema::create('payment_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->decimal('claimed_amount', 15, 2);
            $table->date('claimed_payment_date')->nullable();
            $table->string('payment_method', 32)->nullable();
            $table->string('customer_reference')->nullable();
            $table->text('supporting_info')->nullable();
            $table->string('source_channel', 64)->nullable();
            $table->string('status', 32)->default('pending_verification');
            $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
            $table->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_claims');
        Schema::dropIfExists('payments');
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('receipt_next_sequence');
        });
    }
};
