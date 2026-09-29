<?php

namespace Tests\Feature;

use App\Ai\Providers\FakeAiProvider;
use App\Contracts\WhatsAppDeliveryAdapter;
use App\Enums\AiProcessingStatus;
use App\Enums\CommunicationChannel;
use App\Enums\ConversationMode;
use App\Enums\MessageActorType;
use App\Models\AuditEvent;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\ConversationNeedsHumanAttention;
use App\Services\AiService;
use App\Services\ConversationService;
use App\Support\Permissions;
use App\Support\WhatsAppDeliveryPayload;
use App\Support\WhatsAppDeliveryResult;
use App\Support\WhatsAppDocumentPayload;
use App\Support\WhatsAppTextPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\TestCase;

class HumanAttentionTest extends TestCase
{
    use CreatesFoundationUsers;
    use RefreshDatabase;

    /** @var list<WhatsAppTextPayload> */
    private array $sentTexts = [];

    protected function setUp(): void
    {
        parent::setUp();
        FakeAiProvider::reset();
        config(['adman.ai.enabled' => true]);

        $sent = &$this->sentTexts;
        $this->app->instance(WhatsAppDeliveryAdapter::class, new class($sent) implements WhatsAppDeliveryAdapter
        {
            /** @param list<WhatsAppTextPayload> $sent */
            public function __construct(private array &$sent) {}

            public function sendTemplate(WhatsAppDeliveryPayload $payload): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::ok('wamid.TEMPLATE');
            }

            public function sendText(WhatsAppTextPayload $payload): WhatsAppDeliveryResult
            {
                $this->sent[] = $payload;

                return WhatsAppDeliveryResult::ok('wamid.TEXT.'.count($this->sent));
            }

            public function uploadMedia(string $absolutePath, string $mimeType, string $filename): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::ok('media.1');
            }

            public function sendDocument(WhatsAppDocumentPayload $payload): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::ok('wamid.DOC');
            }
        });
    }

    private function aiConversation(string $number = '2348011111111'): Conversation
    {
        Business::current()->update([
            'ai_enabled' => true,
            'ai_customer_responses_enabled' => true,
            'outbound_whatsapp_enabled' => true,
        ]);

        $customer = Contact::factory()->customer()->create([
            'whatsapp_id' => $number,
            'whatsapp_opt_in' => true,
        ]);

        $service = app(ConversationService::class);
        $identity = $service->findOrCreateIdentity(CommunicationChannel::WhatsApp, $number, $customer->display_name, $customer);

        return $service->openConversation($identity, ConversationMode::Ai);
    }

    private function limitedViewer(): User
    {
        $this->seedRolesAndPermissions();
        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::findOrCreate('Viewer', 'web');
        $role->syncPermissions([Permissions::CONVERSATIONS_VIEW, Permissions::MESSAGES_VIEW]);
        $user->assignRole($role);

        return $user;
    }

    public function test_ai_escalation_notifies_active_takeover_staff_once(): void
    {
        Notification::fake();
        $admin = $this->createSuperAdmin();
        $staff = $this->createStaffUser();
        $inactive = $this->createStaffUser(['is_active' => false]);
        $viewer = $this->limitedViewer();

        $conversation = $this->aiConversation();
        $inbound = app(ConversationService::class)->recordInboundMessage($conversation, 'I want to speak to a human');

        $first = app(AiService::class)->processInboundMessage($inbound);
        $second = app(AiService::class)->processInboundMessage($inbound);
        app(ConversationService::class)->escalateToHuman($conversation->fresh(), 'retry');
        app(ConversationService::class)->recordInboundMessage($conversation->fresh(), 'Hello?');

        $this->assertSame(AiProcessingStatus::Completed, $first->status);
        $this->assertSame($first->id, $second->id);
        $this->assertTrue($conversation->fresh()->needsHumanAttention());

        Notification::assertSentToTimes($admin, ConversationNeedsHumanAttention::class, 1);
        Notification::assertSentToTimes($staff, ConversationNeedsHumanAttention::class, 1);
        Notification::assertNotSentTo($inactive, ConversationNeedsHumanAttention::class);
        Notification::assertNotSentTo($viewer, ConversationNeedsHumanAttention::class);
        Notification::assertCount(2);
        $this->assertSame(1, AuditEvent::query()->where('event', 'conversation.escalated_to_human')->count());
    }

    public function test_notification_content_includes_conversation_and_reason(): void
    {
        Notification::fake();
        $staff = $this->createStaffUser();
        $conversation = $this->aiConversation();

        app(ConversationService::class)->escalateToHuman($conversation, 'Customer requested to speak with the support team.');

        Notification::assertSentTo($staff, ConversationNeedsHumanAttention::class, function (ConversationNeedsHumanAttention $notification) use ($staff, $conversation) {
            $mail = $notification->toMail($staff);
            $text = implode("\n", [...$mail->introLines, ...$mail->outroLines]);

            return $mail->subject === 'Conversation needs human attention'
                && str_contains($text, 'Conversation #'.$conversation->id)
                && str_contains($text, 'Reason: Customer requested to speak with the support team.')
                && $mail->actionUrl === url('/conversations/'.$conversation->id)
                && $notification->toArray($staff)['conversation_id'] === $conversation->id;
        });
    }

    public function test_return_to_ai_then_new_escalation_notifies_again(): void
    {
        Notification::fake();
        $staff = $this->createStaffUser();
        $conversation = $this->aiConversation();
        $service = app(ConversationService::class);

        $service->escalateToHuman($conversation, 'first');
        $service->returnToAi($conversation->fresh());
        $service->escalateToHuman($conversation->fresh(), 'second');

        Notification::assertSentToTimes($staff, ConversationNeedsHumanAttention::class, 2);
    }

    public function test_normal_ai_reply_does_not_notify_and_is_formatted_for_whatsapp(): void
    {
        Notification::fake();
        $this->createStaffUser();
        $conversation = $this->aiConversation();
        $inbound = app(ConversationService::class)->recordInboundMessage($conversation, 'What is your website?');

        $raw = 'The website is [https://raslordeckltd.com/](https://raslordeckltd.com/). **Thanks**';
        FakeAiProvider::respondWith($raw);

        app(AiService::class)->processInboundMessage($inbound);

        Notification::assertNothingSent();
        $this->assertSame(ConversationMode::Ai, $conversation->fresh()->mode);

        $stored = Message::query()->where('actor_type', MessageActorType::Ai->value)->sole();
        $this->assertSame($raw, $stored->body);

        $this->assertCount(1, $this->sentTexts);
        $this->assertSame('The website is https://raslordeckltd.com/. *Thanks*', $this->sentTexts[0]->body);
    }

    public function test_escalated_conversation_offers_take_over_and_shows_reason(): void
    {
        $staff = $this->createStaffUser();
        $conversation = $this->aiConversation();
        app(ConversationService::class)->escalateToHuman($conversation, 'Customer asked for a person');

        $this->actingAs($staff)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('conversations/Show')
                ->where('permissions.take_over_available', true)
                ->where('conversation.needs_attention', true)
                ->where('conversation.handoff_reason', 'Customer asked for a person'));
    }

    public function test_staff_can_take_over_escalated_conversation(): void
    {
        $staff = $this->createStaffUser();
        $other = $this->createStaffUser();
        $conversation = $this->aiConversation();
        app(ConversationService::class)->escalateToHuman($conversation, 'Needs a person');

        $this->actingAs($staff)
            ->post(route('conversations.take-over', $conversation))
            ->assertRedirect()
            ->assertSessionHas('success', 'You are now handling this conversation. AI replies are paused.');

        $conversation->refresh();
        $this->assertSame(ConversationMode::Human, $conversation->mode);
        $this->assertSame($staff->id, $conversation->assigned_user_id);
        $this->assertNull($conversation->closed_at);
        $this->assertFalse($conversation->needsHumanAttention());
        $this->assertDatabaseHas('audit_events', [
            'event' => 'conversation.taken_over',
            'auditable_id' => $conversation->id,
            'actor_id' => $staff->id,
        ]);

        $inbound = app(ConversationService::class)->recordInboundMessage($conversation, 'Are you there?');
        $processing = app(AiService::class)->processInboundMessage($inbound);
        $this->assertSame(AiProcessingStatus::Skipped, $processing->status);

        $this->actingAs($other)
            ->get(route('conversations.show', $conversation))
            ->assertInertia(fn ($page) => $page->where('permissions.take_over_available', false));

        $this->actingAs($staff)
            ->get(route('conversations.show', $conversation))
            ->assertInertia(fn ($page) => $page
                ->where('permissions.take_over_available', false)
                ->where('conversation.handoff_reason', 'Needs a person'));
    }

    public function test_take_over_hidden_without_permission_or_when_closed(): void
    {
        $viewer = $this->limitedViewer();
        $staff = $this->createStaffUser();
        $conversation = $this->aiConversation();

        $this->actingAs($viewer)
            ->get(route('conversations.show', $conversation))
            ->assertInertia(fn ($page) => $page->where('permissions.take_over_available', false));

        $this->actingAs($staff)
            ->get(route('conversations.show', $conversation))
            ->assertInertia(fn ($page) => $page->where('permissions.take_over_available', true));

        app(ConversationService::class)->close($conversation);

        $this->actingAs($staff)
            ->get(route('conversations.show', $conversation))
            ->assertInertia(fn ($page) => $page->where('permissions.take_over_available', false));
    }

    public function test_needs_attention_filter_and_dashboard_card(): void
    {
        $staff = $this->createStaffUser();
        $service = app(ConversationService::class);

        $waiting = $this->aiConversation('2348011111111');
        $service->escalateToHuman($waiting, 'waiting');

        $claimed = $this->aiConversation('2348022222222');
        $service->escalateToHuman($claimed, 'claimed');
        $service->takeOver($claimed->fresh(), $staff);

        $this->aiConversation('2348033333333');

        $this->actingAs($staff)
            ->get(route('conversations.index', ['attention' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('conversations.data', 1)
                ->where('conversations.data.0.id', $waiting->id)
                ->where('conversations.data.0.needs_attention', true)
                ->where('filters.attention', true));

        $this->actingAs($staff)
            ->get(route('conversations.index'))
            ->assertInertia(fn ($page) => $page->has('conversations.data', 3));

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('metrics.0.key', 'human_attention_required')
                ->where('metrics.0.count', 1)
                ->where('metrics.0.visible', true));
    }
}
