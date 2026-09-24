<?php

namespace Tests\Feature;

use App\Enums\CommunicationChannel;
use App\Enums\ContactStatus;
use App\Enums\ConversationMode;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\AuditEvent;
use App\Models\CommunicationIdentity;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\TestCase;

class CommunicationTest extends TestCase
{
    use CreatesFoundationUsers;
    use RefreshDatabase;

    public function test_guests_cannot_view_conversations(): void
    {
        $this->get(route('conversations.index'))->assertRedirect(route('login'));
    }

    public function test_staff_without_permission_cannot_view_conversations(): void
    {
        $this->seedRolesAndPermissions();

        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::findOrCreate('Limited', 'web');
        $role->syncPermissions([Permissions::SETTINGS_ACCESS]);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('conversations.index'))
            ->assertForbidden();
    }

    public function test_authorized_staff_can_view_conversations(): void
    {
        $staff = $this->createStaffUser();
        Conversation::factory()->create();

        $this->actingAs($staff)
            ->get(route('conversations.index'))
            ->assertOk();
    }

    public function test_unknown_identity_can_exist_without_contact(): void
    {
        $identity = CommunicationIdentity::factory()->create([
            'contact_id' => null,
            'external_id' => '+2348099999999',
        ]);

        $this->assertNull($identity->contact_id);
        $this->assertFalse($identity->isLinked());
    }

    public function test_conversation_can_reference_unknown_identity(): void
    {
        $identity = CommunicationIdentity::factory()->create(['contact_id' => null]);
        $conversation = Conversation::factory()->forIdentity($identity)->create();

        $this->assertNull($conversation->contact_id);
        $this->assertSame($identity->id, $conversation->communication_identity_id);
    }

    public function test_conversation_can_reference_a_contact(): void
    {
        $contact = Contact::factory()->create();
        $identity = CommunicationIdentity::factory()->forContact($contact)->create();
        $conversation = Conversation::factory()->forIdentity($identity)->create();

        $this->assertSame($contact->id, $conversation->contact_id);
    }

    public function test_staff_can_create_conversation_with_unknown_identity(): void
    {
        $staff = $this->createStaffUser();

        $response = $this->actingAs($staff)->post(route('conversations.store'), [
            'channel' => CommunicationChannel::WhatsApp->value,
            'external_id' => '+2348012345678',
            'display_name' => 'WhatsApp Guest',
            'initial_message' => 'Hello from WhatsApp',
        ]);

        $conversation = Conversation::query()->first();
        $this->assertNotNull($conversation);
        $response->assertRedirect(route('conversations.show', $conversation));

        $identity = $conversation->identity;
        $this->assertNotNull($identity);
        $this->assertNull($identity->contact_id);
        $this->assertSame(ConversationMode::Ai, $conversation->mode);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound->value,
            'status' => MessageStatus::Recorded->value,
            'body' => 'Hello from WhatsApp',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'conversation.created',
            'auditable_id' => $conversation->id,
        ]);
    }

    public function test_linking_identity_to_contact_does_not_change_lifecycle(): void
    {
        $staff = $this->createStaffUser();
        $contact = Contact::factory()->create(['status' => ContactStatus::Unknown]);
        $identity = CommunicationIdentity::factory()->create(['contact_id' => null]);
        $conversation = Conversation::factory()->forIdentity($identity)->create();

        $this->actingAs($staff)
            ->post(route('conversations.link-contact', $conversation), [
                'contact_id' => $contact->id,
            ])
            ->assertRedirect();

        $identity->refresh();
        $contact->refresh();
        $conversation->refresh();

        $this->assertSame($contact->id, $identity->contact_id);
        $this->assertSame($contact->id, $conversation->contact_id);
        $this->assertSame(ContactStatus::Unknown, $contact->status);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'communication_identity.linked',
            'auditable_id' => $identity->id,
        ]);
    }

    public function test_unauthorized_linking_is_rejected(): void
    {
        $this->seedRolesAndPermissions();

        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::findOrCreate('ViewerOnly', 'web');
        $role->syncPermissions([
            Permissions::CONVERSATIONS_VIEW,
            Permissions::MESSAGES_VIEW,
        ]);
        $user->assignRole($role);

        $contact = Contact::factory()->create();
        $conversation = Conversation::factory()->create();

        $this->actingAs($user)
            ->post(route('conversations.link-contact', $conversation), [
                'contact_id' => $contact->id,
            ])
            ->assertForbidden();
    }

    public function test_human_takeover_and_return_to_ai(): void
    {
        $staff = $this->createStaffUser();
        $conversation = Conversation::factory()->create([
            'mode' => ConversationMode::Ai,
        ]);

        $this->actingAs($staff)
            ->post(route('conversations.take-over', $conversation))
            ->assertRedirect();

        $conversation->refresh();
        $this->assertSame(ConversationMode::Human, $conversation->mode);
        $this->assertSame($staff->id, $conversation->assigned_user_id);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'conversation.taken_over',
            'auditable_id' => $conversation->id,
        ]);

        $this->actingAs($staff)
            ->post(route('conversations.return-to-ai', $conversation))
            ->assertRedirect();

        $conversation->refresh();
        $this->assertSame(ConversationMode::Ai, $conversation->mode);
        $this->assertNull($conversation->assigned_user_id);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'conversation.returned_to_ai',
            'auditable_id' => $conversation->id,
        ]);
    }

    public function test_close_and_reopen_conversation(): void
    {
        $staff = $this->createStaffUser();
        $conversation = Conversation::factory()->create([
            'mode' => ConversationMode::Human,
        ]);

        $this->actingAs($staff)
            ->post(route('conversations.close', $conversation))
            ->assertRedirect();

        $conversation->refresh();
        $this->assertTrue($conversation->isClosed());
        $this->assertSame(ConversationMode::Closed, $conversation->mode);
        $this->assertNotNull($conversation->closed_at);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'conversation.closed',
            'auditable_id' => $conversation->id,
        ]);

        $this->actingAs($staff)
            ->post(route('conversations.reopen', $conversation))
            ->assertRedirect();

        $conversation->refresh();
        $this->assertFalse($conversation->isClosed());
        $this->assertSame(ConversationMode::Human, $conversation->mode);
        $this->assertNull($conversation->closed_at);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'conversation.reopened',
            'auditable_id' => $conversation->id,
        ]);
    }

    public function test_unauthorized_state_changes_fail(): void
    {
        $this->seedRolesAndPermissions();

        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::findOrCreate('ViewOnlyComm', 'web');
        $role->syncPermissions([
            Permissions::CONVERSATIONS_VIEW,
            Permissions::MESSAGES_VIEW,
        ]);
        $user->assignRole($role);

        $conversation = Conversation::factory()->create();

        $this->actingAs($user)
            ->post(route('conversations.take-over', $conversation))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('conversations.close', $conversation))
            ->assertForbidden();
    }

    public function test_messages_belong_to_conversations_with_direction(): void
    {
        $staff = $this->createStaffUser();
        $conversation = Conversation::factory()->create();
        $service = app(ConversationService::class);

        $inbound = $service->recordInboundMessage($conversation, 'Inbound hello');
        $outbound = $service->composeInternalOutbound($conversation, $staff, 'Staff reply');

        $this->assertSame(MessageDirection::Inbound, $inbound->direction);
        $this->assertSame(MessageDirection::Outbound, $outbound->direction);
        $this->assertSame(MessageStatus::Recorded, $outbound->status);
        $this->assertSame($conversation->id, $inbound->conversation_id);
        $this->assertSame($conversation->id, $outbound->conversation_id);
    }

    public function test_message_history_is_preserved_and_visible(): void
    {
        $staff = $this->createStaffUser();
        $conversation = Conversation::factory()->create();
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'body' => 'Preserved message',
            'channel' => $conversation->channel,
        ]);

        $this->actingAs($staff)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('conversations/Show')
                ->has('messages', 1)
                ->where('messages.0.body', 'Preserved message'));
    }

    public function test_unauthorized_users_cannot_access_messages(): void
    {
        $this->seedRolesAndPermissions();

        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::findOrCreate('NoMessages', 'web');
        $role->syncPermissions([Permissions::CONVERSATIONS_VIEW]);
        $user->assignRole($role);

        $conversation = Conversation::factory()->create();

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertForbidden();
    }

    public function test_invalid_compose_is_rejected(): void
    {
        $staff = $this->createStaffUser();
        $conversation = Conversation::factory()->create();

        $this->actingAs($staff)
            ->post(route('conversations.messages.store', $conversation), [
                'body' => '',
            ])
            ->assertSessionHasErrors('body');
    }

    public function test_compose_creates_internal_recorded_outbound_only(): void
    {
        $staff = $this->createStaffUser();
        $conversation = Conversation::factory()->human()->create();

        $this->actingAs($staff)
            ->post(route('conversations.messages.store', $conversation), [
                'body' => 'Internal note to customer',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound->value,
            'status' => MessageStatus::Recorded->value,
            'body' => 'Internal note to customer',
        ]);
    }

    public function test_contact_archive_does_not_destroy_communication_history(): void
    {
        $staff = $this->createStaffUser();
        $contact = Contact::factory()->create();
        $identity = CommunicationIdentity::factory()->forContact($contact)->create();
        $conversation = Conversation::factory()->forIdentity($identity)->create();
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'channel' => $conversation->channel,
            'body' => 'Keep me',
        ]);

        $this->actingAs($staff)
            ->post(route('contacts.archive', $contact))
            ->assertRedirect();

        $contact->refresh();
        $this->assertTrue($contact->isArchived());
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Keep me']);
        $this->assertDatabaseHas('communication_identities', ['id' => $identity->id]);
    }

    public function test_cannot_compose_on_closed_conversation(): void
    {
        $staff = $this->createStaffUser();
        $conversation = Conversation::factory()->closed()->create();

        $this->actingAs($staff)
            ->post(route('conversations.messages.store', $conversation), [
                'body' => 'Should fail',
            ])
            ->assertSessionHasErrors('body');
    }

    public function test_conversation_search_filters_by_mode_and_channel(): void
    {
        $staff = $this->createStaffUser();

        Conversation::factory()->create([
            'mode' => ConversationMode::Ai,
            'channel' => CommunicationChannel::WhatsApp,
        ]);
        Conversation::factory()->create([
            'mode' => ConversationMode::Human,
            'channel' => CommunicationChannel::Email,
        ]);

        $this->actingAs($staff)
            ->get(route('conversations.index', [
                'mode' => ConversationMode::Human->value,
                'channel' => CommunicationChannel::Email->value,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('conversations/Index')
                ->has('conversations.data', 1)
                ->where('conversations.data.0.mode', ConversationMode::Human->value));
    }

    public function test_audit_events_are_not_created_for_every_message(): void
    {
        $staff = $this->createStaffUser();
        $conversation = Conversation::factory()->create();
        $before = AuditEvent::query()->count();

        app(ConversationService::class)->composeInternalOutbound(
            $conversation,
            $staff,
            'No audit for ordinary messages',
        );

        $this->assertSame($before, AuditEvent::query()->count());
    }
}
