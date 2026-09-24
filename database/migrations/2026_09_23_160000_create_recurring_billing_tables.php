<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_billing_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('frequency', 32);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_generation_date')->nullable();
            $table->string('status', 32)->default('active');
            $table->unsignedSmallInteger('payment_term_days')->default(14);
            $table->string('currency_code', 3);
            $table->string('discount_type', 16)->default('none');
            $table->decimal('discount_value', 15, 4)->default(0);
            $table->boolean('tax_enabled')->default(false);
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'next_generation_date']);
            $table->index('contact_id');
            $table->index('frequency');
        });

        Schema::create('recurring_billing_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('recurring_billing_schedules')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('description');
            $table->decimal('quantity', 15, 4);
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 15, 2);
            $table->timestamps();

            $table->index(['schedule_id', 'position']);
        });

        Schema::create('recurring_billing_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('recurring_billing_schedules')->restrictOnDelete();
            $table->string('period_key', 32);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 32)->default('pending');
            $table->foreignId('invoice_id')->nullable()->unique()->constrained('invoices')->nullOnDelete();
            $table->string('trigger', 32)->default('scheduler');
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['schedule_id', 'period_key']);
            $table->index(['schedule_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_billing_generations');
        Schema::dropIfExists('recurring_billing_items');
        Schema::dropIfExists('recurring_billing_schedules');
    }
};
