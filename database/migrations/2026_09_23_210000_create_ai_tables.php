<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('ai_enabled')->default(false)->after('invoice_reminders_enabled');
            $table->boolean('ai_customer_responses_enabled')->default(false)->after('ai_enabled');
        });

        Schema::create('ai_message_processings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbound_message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('outbound_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('status', 32)->default('pending');
            $table->string('skip_reason')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique('inbound_message_id');
            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_message_processings');

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['ai_enabled', 'ai_customer_responses_enabled']);
        });
    }
};
