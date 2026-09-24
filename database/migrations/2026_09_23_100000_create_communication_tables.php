<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_identities', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 32);
            $table->string('external_id');
            $table->string('display_name')->nullable();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['channel', 'external_id']);
            $table->index('contact_id');
            $table->index('is_active');
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('communication_identity_id')->constrained('communication_identities')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('channel', 32);
            $table->string('mode', 32)->default('ai');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('channel');
            $table->index('mode');
            $table->index('last_message_at');
            $table->index('contact_id');
            $table->index('assigned_user_id');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->restrictOnDelete();
            $table->string('direction', 16);
            $table->string('channel', 32);
            $table->text('body');
            $table->string('status', 32)->default('recorded');
            $table->string('actor_type', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('external_message_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['conversation_id', 'occurred_at']);
            $table->index('direction');
            $table->index('status');
            $table->unique(['channel', 'external_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('communication_identities');
    }
};
