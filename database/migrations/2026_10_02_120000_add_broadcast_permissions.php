<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Broadcast permissions (Task 038), granted to Super Administrator only.
 *
 * Production deploys run migrations but not seeders, so the permissions are created here.
 * `RolesAndPermissionsSeeder` defines the same state for fresh installs.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['broadcasts.manage', 'broadcasts.send'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $superAdmin = Role::query()
            ->where('name', User::ROLE_SUPER_ADMINISTRATOR)
            ->where('guard_name', 'web')
            ->first();

        $superAdmin?->givePermissionTo(self::PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
