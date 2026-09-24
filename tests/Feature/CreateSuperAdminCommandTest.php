<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_super_admin_locally(): void
    {
        $this->artisan('adman:create-super-admin', [
            '--name' => 'Local Admin',
            '--email' => 'local-admin@example.com',
            '--password' => 'LocalPassword123!',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'local-admin@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole(User::ROLE_SUPER_ADMINISTRATOR));
        $this->assertTrue($user->is_active);
    }

    public function test_refuses_to_run_in_production(): void
    {
        $previous = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->artisan('adman:create-super-admin', [
                '--name' => 'Nope',
                '--email' => 'nope@example.com',
                '--password' => 'LocalPassword123!',
            ])->assertFailed();

            $this->assertDatabaseMissing('users', ['email' => 'nope@example.com']);
        } finally {
            $this->app['env'] = $previous;
        }
    }

    public function test_staging_requires_force_flag(): void
    {
        $previous = $this->app['env'];
        $this->app['env'] = 'staging';

        try {
            $this->artisan('adman:create-super-admin', [
                '--name' => 'Staging Admin',
                '--email' => 'staging-admin@example.com',
                '--password' => 'StagingPassword123!',
            ])->assertFailed();

            $this->artisan('adman:create-super-admin', [
                '--name' => 'Staging Admin',
                '--email' => 'staging-admin@example.com',
                '--password' => 'StagingPassword123!',
                '--force-staging' => true,
            ])->assertSuccessful();
        } finally {
            $this->app['env'] = $previous;
        }
    }
}
