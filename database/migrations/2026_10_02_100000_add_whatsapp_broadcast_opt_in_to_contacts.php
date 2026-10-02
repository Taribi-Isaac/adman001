<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit WhatsApp broadcast (marketing) opt-in (Task 038).
 *
 * Nullable with no default: no existing contact becomes broadcast-eligible. The
 * transactional `whatsapp_opt_in` is not touched and never counts as broadcast consent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('whatsapp_broadcast_opt_in_at')->nullable()->after('whatsapp_opt_in_source');
            $table->string('whatsapp_broadcast_opt_in_source', 32)->nullable()->after('whatsapp_broadcast_opt_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_broadcast_opt_in_at', 'whatsapp_broadcast_opt_in_source']);
        });
    }
};
