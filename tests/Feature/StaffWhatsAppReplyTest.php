<?php

namespace Tests\Feature;

use App\Ai\Providers\FakeAiProvider;
use App\Contracts\WhatsAppDeliveryAdapter;
use App\Enums\CommunicationChannel;
use App\Enums\ConversationMode;
use App\Enums\MessageActorType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\WhatsAppOutboundService;
use App\Support\Permissions;
use App\Support\WhatsAppDeliveryPayload;
use App\Support\WhatsAppDeliveryResult;
use App\Support\WhatsAppDocumentPayload;
use App\Support\WhatsAppTextPayload;
use App\WhatsApp\Adapters\WhatsAppCloudApiAdapter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\TestCase;

class StaffWhatsAppReplyTest extends TestCase
{
    use CreatesFoundationUsers;
    use RefreshDatabase;

    /** @var object{sent: list<WhatsAppTextPayload>, results: list<WhatsAppDeliveryResult>} */
    private object $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        FakeAiProvider::reset();
        config(['adman.ai.enabled' => true]);

        $this->adapter = new class implements WhatsAppDeliveryAdapter
        {
            /** @var list<WhatsAppTextPayload> */
            public array $sent = [];

            /** @var list<WhatsAppDeliveryResult> */
            public array $results = [];

            public function sendTemplate(WhatsAppDeliveryPayload $payload): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::ok('wamid.TEMPLATE');
            }

            public function sendText(WhatsAppTextPayload $payload): WhatsAppDeliveryResult
            {
                $this->sent[] = $payload;

                return array_shift($this->results) ?? WhatsAppDeliveryResult::ok('wamid.STAFF.'.count($this->sent));
            }

            public function uploadMedia(string $absolutePath, string $mimeType, string $filename): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::ok('media.1');
            }

            public function sendDocument(WhatsAppDocumentPayload $payload): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::ok('wamid.DOC');
            }
        };
        $this->app->instance(WhatsAppDeliveryAdapter::class, $this->adapter);
    }

    /**
     * WhatsApp conversation with a recent inbound message, taken over by $owner.
     */
    private function ownedConversation(User $owner, string $number = '2348011111111'): Conversation
    {
        Business::current()->update([
            'ai_enabled' => true,
            'ai_customer_responses_enabled' => true,
            'outbound_whatsapp_enabled' => true,
        ]);

        $customer = Contact::factory()->customer()->create(['whatsapp_id' => $number, 'whatsapp_opt_in' => true]);
        $service = app(ConversationService::class);
        $identity = $service->findOrCreateIdentity(CommunicationChannel::WhatsApp, $number, $customer->display_name, $customer);
        $conversation = $service->openConversation($identity, ConversationMode::Ai);
        $service->recordInboundMessage($conversation, 'I need to speak to someone');

        return $service->takeOver($conversation, $owner);
    }

    private function userWithout(string $permission): User
    {
        $this->seedRolesAndPermissions();
        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::findOrCreate('No '.$permission, 'web');
        $role->syncPermissions(array_values(array_diff(Permissions::all(), [$permission])));
        $user->assignRole($role);

        return $user;
    }

    private function staffMessages(): Builder
    {
        return Message::query()
            ->where('direction', MessageDirection::Outbound->value)
            ->where('actor_type', MessageActorType::Staff->value);
    }

    public function test_assigned_staff_reply_is_sent_as_written_and_ai_stays_paused(): void
    {
        Notification::fake();
        $staff = $this->createStaffUser();
        $conversation = $this->ownedConversation($staff);
        $body = "Hello, this is Ada from the team.\n\nSee **pricing** at [site](https://example.com/p?a=1&b=2) or https://example.com.";

        $this->actingAs($staff)
            ->from(route('conversations.show', $conversation))
            ->post(route('conversations.whatsapp-reply', $conversation), ['body' => $body])
            ->assertRedirect(route('conversations.show', $conversation))
            ->assertSessionHas('success');

        $message = $this->staffMessages()->sole();
        $this->assertSame($conversation->id, $message->conversation_id);
        $this->assertSame(CommunicationChannel::WhatsApp, $message->channel);
        $this->assertSame($staff->id, $message->actor_user_id);
        $this->assertSame($body, $message->body);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame('wamid.STAFF.1', $message->external_message_id);
        $this->assertSame('session_text', $message->meta['delivery_kind']);

        $this->assertCount(1, $this->adapter->sent);
        $this->assertSame($body, $this->adapter->sent[0]->body);
        $this->assertSame('2348011111111', $this->adapter->sent[0]->to);

        $this->assertDatabaseHas('audit_events', [
            'event' => 'whatsapp.staff_reply_queued',
            'auditable_id' => $message->id,
            'actor_id' => $staff->id,
        ]);
        $this->assertDatabaseHas('audit_events', ['event' => 'whatsapp.sent', 'auditable_id' => $message->id]);

        $conversation->refresh();
        $this->assertSame(ConversationMode::Human, $conversation->mode);
        $this->assertSame($staff->id, $conversation->assigned_user_id);
        $this->assertDatabaseCount('ai_message_processings', 0);
        $this->assertSame(0, Message::query()->where('actor_type', MessageActorType::Ai->value)->count());
        Notification::assertNothingSent();
    }

    public function test_guests_and_users_without_send_permission_cannot_reply(): void
    {
        $staff = $this->createStaffUser();
        $conversation = $this->ownedConversation($staff);

        $this->post(route('conversations.whatsapp-reply', $conversation), ['body' => 'Hi'])
            ->assertRedirect(route('login'));

        $noSend = $this->userWithout(Permissions::MESSAGES_SEND);
        app(ConversationService::class)->takeOver($conversation->fresh(), $noSend);

        $this->actingAs($noSend)
            ->post(route('conversations.whatsapp-reply', $conversation), ['body' => 'Hi'])
            ->assertForbidden();

        $this->assertSame(0, $this->staffMessages()->count());
        $this->assertCount(0, $this->adapter->sent);
    }

    public function test_reply_rejected_unless_open_human_and_assigned_to_current_user(): void
    {
        $owner = $this->createStaffUser();
        $other = $this->createStaffUser();
        $service = app(ConversationService::class);
        $conversation = $this->ownedConversation($owner);

        $attempt = fn (User $user) => $this->actingAs($user)
            ->from(route('conversations.show', $conversation))
            ->post(route('conversations.whatsapp-reply', $conversation), ['body' => 'Hello'])
            ->assertRedirect(route('conversations.show', $conversation))
            ->assertSessionHasErrors('body');

        $attempt($other);

        $service->returnToAi($conversation->fresh());
        $attempt($owner);

        $service->escalateToHuman($conversation->fresh(), 'test');
        $attempt($owner);

        $service->takeOver($conversation->fresh(), $owner);
        $service->close($conversation->fresh());
        $attempt($owner);

        $this->assertSame(0, $this->staffMessages()->count());
        $this->assertCount(0, $this->adapter->sent);
    }

    public function test_reply_blocked_outside_customer_service_window(): void
    {
        $staff = $this->createStaffUser();
        $conversation = $this->ownedConversation($staff);
        Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Inbound->value)
            ->update(['occurred_at' => now()->subHours(25)]);

        $this->actingAs($staff)
            ->get(route('conversations.show', $conversation))
            ->assertInertia(fn ($page) => $page
                ->where('whatsappReply.available', false)
                ->where('whatsappReply.reason', fn (string $reason) => str_contains($reason, '24 hours')));

        $this->actingAs($staff)
            ->from(route('conversations.show', $conversation))
            ->post(route('conversations.whatsapp-reply', $conversation), ['body' => 'Hello'])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, $this->staffMessages()->count());
        $this->assertCount(0, $this->adapter->sent);
    }

    public function test_window_uses_latest_inbound_for_the_identity(): void
    {
        $staff = $this->createStaffUser();
        $conversation = $this->ownedConversation($staff);
        Message::query()->where('conversation_id', $conversation->id)->update(['occurred_at' => now()->subHours(23)]);

        $whatsapp = app(WhatsAppOutboundService::class);
        $this->assertTrue($whatsapp->customerServiceWindowOpen($conversation));
        $this->assertEqualsWithDelta(
            now()->addHour()->getTimestamp(),
            $whatsapp->customerServiceWindowExpiresAt($conversation)->getTimestamp(),
            5,
        );
    }

    public function test_show_page_reports_reply_availability(): void
    {
        $owner = $this->createStaffUser();
        $other = $this->createStaffUser();
        $conversation = $this->ownedConversation($owner);

        $this->actingAs($owner)
            ->get(route('conversations.show', $conversation))
            ->assertInertia(fn ($page) => $page
                ->where('whatsappReply.available', true)
                ->where('whatsappReply.reason', null)
                ->whereNot('whatsappReply.window_expires_at', null));

        $this->actingAs($other)
            ->get(route('conversations.show', $conversation))
            ->assertInertia(fn ($page) => $page
                ->where('whatsappReply.available', false)
                ->where('whatsappReply.reason', fn (string $reason) => str_contains($reason, $owner->name)));
    }

    public function test_permanent_provider_failure_is_recorded_as_failed(): void
    {
        $staff = $this->createStaffUser();
        $conversation = $this->ownedConversation($staff);
        $this->adapter->results = [WhatsAppDeliveryResult::failed('WhatsApp rejected the message.', retryable: false)];

        $this->actingAs($staff)
            ->post(route('conversations.whatsapp-reply', $conversation), ['body' => 'Hello'])
            ->assertRedirect();

        $message = $this->staffMessages()->sole();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertSame('WhatsApp rejected the message.', $message->failure_reason);
        $this->assertNull($message->external_message_id);
    }

    public function test_transient_failure_then_retry_delivers_the_same_message_once(): void
    {
        Queue::fake();
        $staff = $this->createStaffUser();
        $conversation = $this->ownedConversation($staff);
        $whatsapp = app(WhatsAppOutboundService::class);

        $message = $whatsapp->queueStaffSessionReply($conversation, $staff, 'Hello again');
        $this->assertSame(MessageStatus::Pending, $message->status);

        $this->adapter->results = [WhatsAppDeliveryResult::failed('WhatsApp rate limit reached.', retryable: true)];
        try {
            $whatsapp->deliverQueuedMessage($message);
            $this->fail('Transient failure should be rethrown for queue retry.');
        } catch (\RuntimeException) {
        }
        $this->assertSame(MessageStatus::Failed, $message->fresh()->status);

        $whatsapp->deliverQueuedMessage($message->fresh());
        $whatsapp->deliverQueuedMessage($message->fresh());

        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);
        $this->assertSame(1, $this->staffMessages()->count());
        $this->assertCount(2, $this->adapter->sent);
    }

    public function test_retry_of_session_text_is_refused_after_window_closes(): void
    {
        $staff = $this->createStaffUser();
        $conversation = $this->ownedConversation($staff);
        $this->adapter->results = [WhatsAppDeliveryResult::failed('boom', retryable: false)];
        $this->actingAs($staff)->post(route('conversations.whatsapp-reply', $conversation), ['body' => 'Hello']);
        $message = $this->staffMessages()->sole();

        Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Inbound->value)
            ->update(['occurred_at' => now()->subHours(30)]);

        $this->actingAs($staff)
            ->from(route('conversations.show', $conversation))
            ->post(route('messages.retry-email', $message))
            ->assertSessionHasErrors('message');

        $this->assertSame(MessageStatus::Failed, $message->fresh()->status);
        $this->assertCount(1, $this->adapter->sent);
    }

    public function test_cloud_api_window_error_is_reported_clearly(): void
    {
        config([
            'adman.whatsapp.access_token' => 'test-token',
            'adman.whatsapp.phone_number_id' => '123456789',
        ]);
        Http::fake([
            '*' => Http::response(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400),
        ]);

        $result = app(WhatsAppCloudApiAdapter::class)->sendText(new WhatsAppTextPayload(
            to: '2348011111111',
            body: 'Hello',
            messageId: 1,
        ));

        $this->assertFalse($result->success);
        $this->assertFalse($result->retryable);
        $this->assertStringContainsString('24-hour customer service window', (string) $result->failureReason);
    }

    public function test_ai_reply_still_formatted_and_internal_note_still_internal(): void
    {
        $staff = $this->createStaffUser();
        $conversation = $this->ownedConversation($staff);

        app(WhatsAppOutboundService::class)->queueAiSessionReply($conversation, 'Visit [site](https://example.com) **now**');
        $this->assertSame('Visit https://example.com *now*', $this->adapter->sent[0]->body);

        $this->actingAs($staff)
            ->post(route('conversations.messages.store', $conversation), ['body' => 'Internal only'])
            ->assertRedirect();

        $note = Message::query()->where('body', 'Internal only')->sole();
        $this->assertSame(MessageStatus::Recorded, $note->status);
        $this->assertCount(1, $this->adapter->sent);
    }
}
