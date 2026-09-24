<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Business;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Database\Seeders\BusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use CreatesFoundationUsers;
    use RefreshDatabase;

    public function test_application_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_guests_are_redirected_from_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_authenticated_super_admin_can_view_dashboard(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Nope',
            'email' => 'nope@example.com',
            'password' => 'Password123!@#',
            'password_confirmation' => 'Password123!@#',
        ])->assertNotFound();
    }

    public function test_super_admin_can_view_and_update_business_settings(): void
    {
        $this->seed(BusinessSeeder::class);
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->get(route('settings.business.edit'))
            ->assertOk();

        $response = $this->actingAs($admin)->put(route('settings.business.update'), [
            'name' => 'Updated Business',
            'legal_name' => 'Updated Business Ltd',
            'registration_number' => null,
            'email' => 'ops@example.com',
            'phone' => null,
            'website' => null,
            'address_line_1' => null,
            'address_line_2' => null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
            'country' => 'Nigeria',
            'tax_enabled' => true,
            'tax_name' => 'VAT',
            'tax_rate' => '7.5',
            'tax_identification' => null,
            'currency_code' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'bank_name' => null,
            'bank_account_name' => null,
            'bank_account_number' => null,
            'payment_instructions' => null,
            'default_terms' => null,
            'invoice_number_prefix' => 'INV-',
            'quote_number_prefix' => 'QT-',
            'receipt_number_prefix' => 'RCPT-',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('businesses', [
            'name' => 'Updated Business',
            'timezone' => 'Africa/Lagos',
            'currency_code' => 'NGN',
        ]);

        $this->assertDatabaseHas('audit_events', [
            'event' => 'business.settings_updated',
        ]);
    }

    public function test_staff_without_permission_cannot_update_business_settings(): void
    {
        $this->seed(BusinessSeeder::class);
        $staff = $this->createStaffUser();

        $this->actingAs($staff)
            ->put(route('settings.business.update'), [
                'name' => 'Hacked',
                'tax_enabled' => false,
                'currency_code' => 'NGN',
                'timezone' => 'UTC',
            ])
            ->assertForbidden();
    }

    public function test_staff_without_users_view_cannot_access_user_management(): void
    {
        $staff = $this->createStaffUser();

        $this->actingAs($staff)
            ->get(route('settings.users.index'))
            ->assertForbidden();
    }

    public function test_super_admin_can_create_staff_user(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->post(route('settings.users.store'), [
                'name' => 'Finance Staff',
                'email' => 'finance@example.com',
                'password' => 'Password123!@#',
                'password_confirmation' => 'Password123!@#',
                'role' => User::ROLE_STAFF,
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'finance@example.com',
            'is_active' => true,
        ]);

        $created = User::query()->where('email', 'finance@example.com')->first();
        $this->assertTrue($created?->hasRole(User::ROLE_STAFF));
    }

    public function test_inactive_users_cannot_access_authenticated_routes(): void
    {
        $user = $this->createStaffUser(['is_active' => false]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_audit_logger_records_events(): void
    {
        $admin = $this->createSuperAdmin();
        $this->actingAs($admin);

        app(AuditLogger::class)->record(
            event: 'test.event',
            description: 'Foundation audit check',
            auditable: $admin,
        );

        $this->assertDatabaseHas('audit_events', [
            'event' => 'test.event',
            'actor_id' => $admin->id,
        ]);

        $this->assertSame(1, AuditEvent::query()->count());
    }

    public function test_business_current_creates_singleton_record(): void
    {
        $first = Business::current();
        $second = Business::current();

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Business::query()->count());
    }

    public function test_permission_constants_cover_foundation_only(): void
    {
        $this->assertContains(Permissions::BUSINESS_UPDATE, Permissions::all());
        $this->assertContains(Permissions::PAYMENTS_RECORD, Permissions::all());
        $this->assertContains(Permissions::RECURRING_BILLING_CREATE, Permissions::all());
        $this->assertNotContains('payments.gateway', Permissions::all());
    }
}
