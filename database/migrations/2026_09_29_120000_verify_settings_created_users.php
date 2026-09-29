<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Users created through Settings → Users were meant to be pre-verified, but the value
 * was discarded by mass assignment. Verify only those users (identified by their
 * `user.created` audit event) that are still unverified.
 */
return new class extends Migration
{
    public function up(): void
    {
        $userIds = DB::table('audit_events')
            ->where('event', 'user.created')
            ->where('auditable_type', User::class)
            ->pluck('auditable_id');

        if ($userIds->isEmpty()) {
            return;
        }

        DB::table('users')
            ->whereIn('id', $userIds)
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Data correction; intentionally not reversed.
    }
};
