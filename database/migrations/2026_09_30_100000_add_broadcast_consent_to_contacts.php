<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consent evidence and broadcast opt-out state (Task 035).
 *
 * All new columns are nullable with no default, so existing contacts start in a
 * non-consenting state for broadcasts. `whatsapp_opt_in` is not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('whatsapp_opt_in_at')->nullable()->after('whatsapp_opt_in');
            $table->string('whatsapp_opt_in_source', 32)->nullable()->after('whatsapp_opt_in_at');
            $table->timestamp('whatsapp_broadcast_opt_out_at')->nullable()->after('whatsapp_opt_in_source');
            $table->string('whatsapp_broadcast_opt_out_source', 32)->nullable()->after('whatsapp_broadcast_opt_out_at');
            $table->timestamp('email_broadcast_opt_in_at')->nullable()->after('whatsapp_broadcast_opt_out_source');
            $table->string('email_broadcast_opt_in_source', 32)->nullable()->after('email_broadcast_opt_in_at');
            $table->timestamp('email_broadcast_unsubscribed_at')->nullable()->after('email_broadcast_opt_in_source');
            $table->string('email_broadcast_unsubscribe_source', 32)->nullable()->after('email_broadcast_unsubscribed_at');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_opt_in_at',
                'whatsapp_opt_in_source',
                'whatsapp_broadcast_opt_out_at',
                'whatsapp_broadcast_opt_out_source',
                'email_broadcast_opt_in_at',
                'email_broadcast_opt_in_source',
                'email_broadcast_unsubscribed_at',
                'email_broadcast_unsubscribe_source',
            ]);
        });
    }
};
