<?php

namespace Tests\Concerns;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

trait CreatesFoundationUsers
{
    protected function seedRolesAndPermissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function createSuperAdmin(array $attributes = []): User
    {
        $this->seedRolesAndPermissions();

        /** @var User $user */
        $user = User::factory()->create($attributes);
        $user->assignRole(User::ROLE_SUPER_ADMINISTRATOR);

        return $user;
    }

    protected function createStaffUser(array $attributes = []): User
    {
        $this->seedRolesAndPermissions();

        /** @var User $user */
        $user = User::factory()->create($attributes);
        $user->assignRole(User::ROLE_STAFF);

        return $user;
    }
}
