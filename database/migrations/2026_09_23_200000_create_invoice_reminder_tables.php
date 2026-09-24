<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('invoice_reminders_enabled')->default(true)->after('outbound_whatsapp_enabled');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('reminder_channel', 32)->default('email')->after('whatsapp_opt_in');
        });

        Schema::create('reminder_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->integer('offset_days');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'offset_days']);
            $table->index(['business_id', 'is_enabled']);
        });

        Schema::create('reminder_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('reminder_rule_id')->constrained('reminder_rules')->restrictOnDelete();
            $table->string('channel', 32);
            $table->date('occurrence_date');
            $table->string('status', 32)->default('pending');
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->text('failure_reason')->nullable();
            $table->string('skip_reason')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['invoice_id', 'reminder_rule_id', 'channel'], 'reminder_occurrences_unique');
            $table->index(['occurrence_date', 'status']);
            $table->index('status');
        });

        $businessId = DB::table('businesses')->orderBy('id')->value('id');
        if ($businessId !== null) {
            $now = now();
            DB::table('reminder_rules')->insert([
                [
                    'business_id' => $businessId,
                    'offset_days' => -7,
                    'is_enabled' => true,
                    'sort_order' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'business_id' => $businessId,
                    'offset_days' => -2,
                    'is_enabled' => true,
                    'sort_order' => 2,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'business_id' => $businessId,
                    'offset_days' => 1,
                    'is_enabled' => true,
                    'sort_order' => 3,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_occurrences');
        Schema::dropIfExists('reminder_rules');

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('reminder_channel');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('invoice_reminders_enabled');
        });
    }
};
