<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedBigInteger('quote_next_sequence')->default(1)->after('receipt_number_prefix');
            $table->unsignedBigInteger('invoice_next_sequence')->default(1)->after('quote_next_sequence');
            $table->unsignedSmallInteger('default_payment_term_days')->default(14)->after('invoice_next_sequence');
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('status', 32)->default('draft');
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('currency_code', 3);
            $table->string('discount_type', 16)->default('none');
            $table->decimal('discount_value', 15, 4)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->boolean('tax_enabled')->default(false);
            $table->string('tax_name')->nullable();
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('taxable_subtotal', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->json('business_snapshot')->nullable();
            $table->json('customer_snapshot')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('contact_id');
            $table->index('issue_date');
            $table->index('expiry_date');
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('description');
            $table->decimal('quantity', 15, 4);
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_subtotal', 15, 2);
            $table->timestamps();

            $table->index(['quote_id', 'position']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('quote_id')->nullable()->unique()->constrained('quotes')->nullOnDelete();
            $table->string('lifecycle_status', 32)->default('draft');
            $table->string('payment_status', 32)->default('unpaid');
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('currency_code', 3);
            $table->string('discount_type', 16)->default('none');
            $table->decimal('discount_value', 15, 4)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->boolean('tax_enabled')->default(false);
            $table->string('tax_name')->nullable();
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('taxable_subtotal', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('balance_due', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->json('business_snapshot')->nullable();
            $table->json('customer_snapshot')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('lifecycle_status');
            $table->index('payment_status');
            $table->index('contact_id');
            $table->index('issue_date');
            $table->index('due_date');
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('description');
            $table->decimal('quantity', 15, 4);
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_subtotal', 15, 2);
            $table->timestamps();

            $table->index(['invoice_id', 'position']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->morphs('documentable');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('filename');
            $table->string('mime_type')->default('application/pdf');
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('access_token_hash', 64)->nullable()->unique();
            $table->timestamp('access_expires_at')->nullable();
            $table->timestamp('access_revoked_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('access_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'quote_next_sequence',
                'invoice_next_sequence',
                'default_payment_term_days',
            ]);
        });
    }
};
