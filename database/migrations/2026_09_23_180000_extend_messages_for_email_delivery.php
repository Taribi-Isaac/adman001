<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('subject')->nullable()->after('body');
            $table->string('template_key', 64)->nullable()->after('subject');
            $table->foreignId('document_id')->nullable()->after('template_key')->constrained('documents')->nullOnDelete();
            $table->text('failure_reason')->nullable()->after('external_message_id');
            $table->timestamp('sent_at')->nullable()->after('failure_reason');
            $table->timestamp('failed_at')->nullable()->after('sent_at');
            $table->json('meta')->nullable()->after('failed_at');

            $table->index('document_id');
            $table->index('template_key');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('outbound_email_enabled')->default(true)->after('email');
            $table->string('email_reply_to')->nullable()->after('outbound_email_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_id');
            $table->dropColumn([
                'subject',
                'template_key',
                'failure_reason',
                'sent_at',
                'failed_at',
                'meta',
            ]);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['outbound_email_enabled', 'email_reply_to']);
        });
    }
};
