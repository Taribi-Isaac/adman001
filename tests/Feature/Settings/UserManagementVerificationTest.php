<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\TestCase;

class UserManagementVerificationTest extends TestCase
{
    use CreatesFoundationUsers;
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function newAdminPayload(): array
    {
        return [
            'name' => 'Second Admin',
            'email' => 'second.admin@example.com',
            'password' => 'Password123!@#',
            'password_confirmation' => 'Password123!@#',
            'role' => User::ROLE_SUPER_ADMINISTRATOR,
            'is_active' => true,
        ];
    }

    public function test_settings_created_administrator_is_verified_and_can_log_in(): void
    {
        Notification::fake();
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->post(route('settings.users.store'), $this->newAdminPayload())
            ->assertRedirect();

        $created = User::query()->where('email', 'second.admin@example.com')->firstOrFail();
        $this->assertNotNull($created->email_verified_at);
        $this->assertTrue($created->hasVerifiedEmail());
        $this->assertTrue($created->hasRole(User::ROLE_SUPER_ADMINISTRATOR));
        Notification::assertNothingSentTo($created);

        auth()->logout();

        $this->post(route('login.store'), [
            'email' => 'second.admin@example.com',
            'password' => 'Password123!@#',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($created);
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('conversations.index'))->assertOk();
    }

    public function test_staff_without_user_create_permission_cannot_create_administrators(): void
    {
        $staff = $this->createStaffUser();

        $this->actingAs($staff)
            ->post(route('settings.users.store'), $this->newAdminPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'second.admin@example.com']);
    }

    public function test_backfill_migration_verifies_only_settings_created_users(): void
    {
        $admin = $this->createSuperAdmin();

        $settingsCreated = User::factory()->unverified()->create();
        app(AuditLogger::class)->record(
            event: 'user.created',
            description: 'Staff user created',
            auditable: $settingsCreated,
            actor: $admin,
        );
        $otherUnverified = User::factory()->unverified()->create();

        $migration = require database_path('migrations/2026_09_29_120000_verify_settings_created_users.php');
        $migration->up();

        $this->assertNotNull($settingsCreated->fresh()->email_verified_at);
        $this->assertNull($otherUnverified->fresh()->email_verified_at);
    }
}
