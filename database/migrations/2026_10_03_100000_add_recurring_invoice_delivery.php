<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing schedules default to `none`: nothing starts being sent without an explicit choice.
        Schema::table('recurring_billing_schedules', function (Blueprint $table) {
            $table->string('delivery_channel', 16)->default('none')->after('payment_term_days');
        });

        Schema::table('recurring_billing_generations', function (Blueprint $table) {
            $table->string('delivery_channel', 16)->nullable()->after('invoice_id');
            $table->foreignId('document_id')->nullable()->after('delivery_channel')->constrained('documents')->nullOnDelete();
            $table->text('pdf_failure_reason')->nullable()->after('document_id');
        });

        Schema::create('recurring_billing_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_id')->constrained('recurring_billing_generations')->restrictOnDelete();
            $table->string('channel', 16);
            $table->string('status', 32)->default('pending');
            $table->foreignId('message_id')->nullable()->unique()->constrained('messages')->nullOnDelete();
            $table->text('failure_reason')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['generation_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_billing_deliveries');

        Schema::table('recurring_billing_generations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_id');
            $table->dropColumn(['delivery_channel', 'pdf_failure_reason']);
        });

        Schema::table('recurring_billing_schedules', function (Blueprint $table) {
            $table->dropColumn('delivery_channel');
        });
    }
};
