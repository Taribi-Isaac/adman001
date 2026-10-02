<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Broadcast foundation (Task 038): one-off broadcasts, their recipient snapshot and the
 * business-level kill switch (off by default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('broadcasts_enabled')->default(false)->after('invoice_reminders_enabled');
        });

        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('channel', 32);
            $table->string('audience_type', 32);
            $table->json('selected_contact_ids')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->string('whatsapp_template_name')->nullable();
            $table->string('whatsapp_template_language', 16)->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('recipient_limit')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->json('exclusion_summary')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('send_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });

        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained('broadcasts')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('communication_identity_id')->nullable()->constrained('communication_identities')->nullOnDelete();
            $table->string('channel', 32);
            $table->string('address')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('message_id')->nullable()->unique()->constrained('messages')->nullOnDelete();
            $table->string('provider_message_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            // One broadcast + one contact = at most one intended message (channel is fixed per broadcast).
            $table->unique(['broadcast_id', 'contact_id']);
            $table->index(['broadcast_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcasts');

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('broadcasts_enabled');
        });
    }
};
