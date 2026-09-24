<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('outbound_whatsapp_enabled')->default(true)->after('email_reply_to');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('whatsapp_opt_in')->default(false)->after('whatsapp_id');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('outbound_whatsapp_enabled');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('whatsapp_opt_in');
        });
    }
};
