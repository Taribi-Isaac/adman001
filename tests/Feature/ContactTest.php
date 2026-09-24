<?php

namespace Tests\Feature;

use App\Enums\ContactStatus;
use App\Enums\ContactType;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use CreatesFoundationUsers;
    use RefreshDatabase;

    public function test_guests_cannot_view_contacts(): void
    {
        $this->get(route('contacts.index'))->assertRedirect(route('login'));
    }

    public function test_staff_without_permission_cannot_view_contacts(): void
    {
        $this->seedRolesAndPermissions();

        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::findOrCreate('Limited', 'web');
        $role->syncPermissions([Permissions::SETTINGS_ACCESS]);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('contacts.index'))
            ->assertForbidden();
    }

    public function test_authorized_staff_can_create_unknown_contact_with_limited_information(): void
    {
        $staff = $this->createStaffUser();

        $response = $this->actingAs($staff)->post(route('contacts.store'), [
            'type' => ContactType::Individual->value,
            'phone' => '+2348011111111',
            'whatsapp_id' => '+2348011111111',
        ]);

        $contact = Contact::query()->first();
        $this->assertNotNull($contact);
        $response->assertRedirect(route('contacts.show', $contact));

        $this->assertSame(ContactStatus::Unknown, $contact->status);
        $this->assertSame('+2348011111111', $contact->display_name);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'contact.created',
            'auditable_id' => $contact->id,
        ]);
    }

    public function test_validation_rejects_contact_without_any_identifier(): void
    {
        $staff = $this->createStaffUser();

        $this->actingAs($staff)
            ->post(route('contacts.store'), [
                'type' => ContactType::Individual->value,
            ])
            ->assertSessionHasErrors('phone');
    }

    public function test_validation_rejects_invalid_email(): void
    {
        $staff = $this->createStaffUser();

        $this->actingAs($staff)
            ->post(route('contacts.store'), [
                'type' => ContactType::Individual->value,
                'email' => 'not-an-email',
                'first_name' => 'Ada',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_unauthorized_staff_cannot_create_contacts(): void
    {
        $this->seedRolesAndPermissions();

        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::findOrCreate('ViewerOnly', 'web');
        $role->syncPermissions([Permissions::CONTACTS_VIEW]);
        $user->assignRole($role);

        $this->actingAs($user)
            ->post(route('contacts.store'), [
                'type' => ContactType::Individual->value,
                'phone' => '+2348022222222',
            ])
            ->assertForbidden();
    }

    public function test_authorized_staff_can_update_contact(): void
    {
        $staff = $this->createStaffUser();
        $contact = Contact::factory()->unknown()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
        ]);

        $this->actingAs($staff)
            ->put(route('contacts.update', $contact), [
                'type' => ContactType::Individual->value,
                'first_name' => 'Augusta',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.com',
                'phone' => $contact->phone,
            ])
            ->assertRedirect(route('contacts.show', $contact));

        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'first_name' => 'Augusta',
            'display_name' => 'Augusta Lovelace',
        ]);
    }

    public function test_unauthorized_staff_cannot_update_contact(): void
    {
        $this->seedRolesAndPermissions();

        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::findOrCreate('ViewOnlyContacts', 'web');
        $role->syncPermissions([Permissions::CONTACTS_VIEW]);
        $user->assignRole($role);

        $contact = Contact::factory()->create();

        $this->actingAs($user)
            ->put(route('contacts.update', $contact), [
                'type' => ContactType::Individual->value,
                'first_name' => 'Nope',
                'phone' => $contact->phone,
            ])
            ->assertForbidden();
    }

    public function test_unknown_can_be_promoted_to_prospect_then_customer(): void
    {
        $staff = $this->createStaffUser();
        $contact = Contact::factory()->unknown()->create();

        $this->actingAs($staff)
            ->post(route('contacts.promote', $contact), [
                'status' => ContactStatus::Prospect->value,
            ])
            ->assertRedirect();

        $this->assertSame(ContactStatus::Prospect, $contact->fresh()->status);

        $this->actingAs($staff)
            ->post(route('contacts.promote', $contact), [
                'status' => ContactStatus::Customer->value,
            ])
            ->assertRedirect();

        $this->assertSame(ContactStatus::Customer, $contact->fresh()->status);
        $this->assertSame(2, AuditEvent::query()->where('event', 'contact.promoted')->count());
    }

    public function test_unknown_can_be_promoted_directly_to_customer(): void
    {
        $staff = $this->createStaffUser();
        $contact = Contact::factory()->unknown()->create();

        $this->actingAs($staff)
            ->post(route('contacts.promote', $contact), [
                'status' => ContactStatus::Customer->value,
            ])
            ->assertRedirect();

        $this->assertSame(ContactStatus::Customer, $contact->fresh()->status);
    }

    public function test_invalid_lifecycle_transition_is_rejected(): void
    {
        $staff = $this->createStaffUser();
        $contact = Contact::factory()->customer()->create();

        $this->actingAs($staff)
            ->post(route('contacts.promote', $contact), [
                'status' => ContactStatus::Prospect->value,
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(ContactStatus::Customer, $contact->fresh()->status);
    }

    public function test_unauthorized_user_cannot_promote_contact(): void
    {
        $this->seedRolesAndPermissions();

        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::findOrCreate('NoPromote', 'web');
        $role->syncPermissions([
            Permissions::CONTACTS_VIEW,
            Permissions::CONTACTS_CREATE,
            Permissions::CONTACTS_UPDATE,
        ]);
        $user->assignRole($role);

        $contact = Contact::factory()->unknown()->create();

        $this->actingAs($user)
            ->post(route('contacts.promote', $contact), [
                'status' => ContactStatus::Prospect->value,
            ])
            ->assertForbidden();
    }

    public function test_duplicate_email_is_rejected_for_active_contacts(): void
    {
        $staff = $this->createStaffUser();
        Contact::factory()->create(['email' => 'dup@example.com']);

        $this->actingAs($staff)
            ->post(route('contacts.store'), [
                'type' => ContactType::Individual->value,
                'first_name' => 'Other',
                'email' => 'dup@example.com',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_duplicate_phone_is_rejected_for_active_contacts(): void
    {
        $staff = $this->createStaffUser();
        Contact::factory()->create(['phone' => '+2348099999999']);

        $this->actingAs($staff)
            ->post(route('contacts.store'), [
                'type' => ContactType::Individual->value,
                'phone' => '+2348099999999',
            ])
            ->assertSessionHasErrors('phone');
    }

    public function test_archived_contact_can_reuse_identifiers_after_archive_and_new_active_blocks_restore_conflict(): void
    {
        $staff = $this->createStaffUser();
        $original = Contact::factory()->create([
            'email' => 'reuse@example.com',
            'phone' => '+2348088888888',
        ]);

        $this->actingAs($staff)
            ->post(route('contacts.archive', $original))
            ->assertRedirect();

        $this->assertNotNull($original->fresh()->archived_at);

        $this->actingAs($staff)
            ->post(route('contacts.store'), [
                'type' => ContactType::Individual->value,
                'first_name' => 'New',
                'email' => 'reuse@example.com',
                'phone' => '+2348077777777',
            ])
            ->assertRedirect();

        $this->actingAs($staff)
            ->post(route('contacts.restore', $original))
            ->assertSessionHasErrors('email');
    }

    public function test_contact_search_and_filters_work(): void
    {
        $staff = $this->createStaffUser();

        Contact::factory()->customer()->create([
            'first_name' => 'Chinonso',
            'last_name' => 'Okafor',
            'display_name' => 'Chinonso Okafor',
            'email' => 'chino@example.com',
            'phone' => '+2348010000001',
        ]);
        Contact::factory()->prospect()->organization()->create([
            'organization_name' => 'Acme Ltd',
            'display_name' => 'Acme Ltd',
            'email' => 'ops@acme.test',
            'phone' => '+2348010000002',
        ]);

        $this->actingAs($staff)
            ->get(route('contacts.index', ['search' => 'Chinonso']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('contacts/Index')
                ->has('contacts.data', 1)
                ->where('contacts.data.0.display_name', 'Chinonso Okafor'));

        $this->actingAs($staff)
            ->get(route('contacts.index', ['status' => ContactStatus::Prospect->value]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('contacts.data', 1)
                ->where('contacts.data.0.status', 'prospect'));

        $this->actingAs($staff)
            ->get(route('contacts.index', ['type' => ContactType::Organization->value]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('contacts.data', 1)
                ->where('contacts.data.0.type', 'organization'));
    }

    public function test_contacts_are_not_hard_deleted_by_archive(): void
    {
        $staff = $this->createStaffUser();
        $contact = Contact::factory()->customer()->create();

        $this->actingAs($staff)->post(route('contacts.archive', $contact));

        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'status' => ContactStatus::Customer->value,
        ]);
        $this->assertNotNull($contact->fresh()->archived_at);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'contact.archived',
            'auditable_id' => $contact->id,
        ]);
    }
}
