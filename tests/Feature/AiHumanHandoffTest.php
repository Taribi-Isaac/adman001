<?php

namespace Tests\Feature;

use App\Ai\Providers\FakeAiProvider;
use App\Contracts\WhatsAppDeliveryAdapter;
use App\Enums\AiProcessingStatus;
use App\Enums\CommunicationChannel;
use App\Enums\ContactStatus;
use App\Enums\ConversationMode;
use App\Enums\DiscountType;
use App\Enums\MessageActorType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\PaymentClaimStatus;
use App\Enums\PaymentMethod;
use App\Jobs\ProcessInboundAiMessage;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\PaymentClaim;
use App\Models\User;
use App\Services\Ai\AiAuthorization;
use App\Services\Ai\AiToolRegistry;
use App\Services\AiService;
use App\Services\ConversationService;
use App\Services\InvoiceService;
use App\Support\AiProviderResponse;
use App\Support\Permissions;
use App\Support\WhatsAppDeliveryPayload;
use App\Support\WhatsAppDeliveryResult;
use App\Support\WhatsAppTextPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\TestCase;

class AiHumanHandoffTest extends TestCase
{
    use CreatesFoundationUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeAiProvider::reset();
        FakeAiProvider::respondWith('Hello from AI.');
        config(['adman.ai.enabled' => true]);
        $this->bindSuccessfulWhatsAppTextAdapter();
    }

    private function bindSuccessfulWhatsAppTextAdapter(): void
    {
        $this->app->instance(WhatsAppDeliveryAdapter::class, new class implements WhatsAppDeliveryAdapter
        {
            public function sendTemplate(WhatsAppDeliveryPayload $payload): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::ok('wamid.TEMPLATE');
            }

            public function sendText(WhatsAppTextPayload $payload): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::ok('wamid.AI.'.md5($payload->body));
            }

            public function uploadMedia(string $absolutePath, string $mimeType, string $filename): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::ok('media.AI');
            }

            public function sendDocument(\App\Support\WhatsAppDocumentPayload $payload): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::ok('wamid.DOC.'.md5($payload->mediaId));
            }
        });
    }

    /**
     * @return list<array{description: string, quantity: string, unit_price: string}>
     */
    private function sampleItems(string $price = '100.00'): array
    {
        return [
            [
                'description' => 'Service',
                'quantity' => '1',
                'unit_price' => $price,
            ],
        ];
    }

    /**
     * @return array{conversation: Conversation, inbound: Message, contact: Contact, staff: User}
     */
    private function linkedAiConversation(string $body = 'Hello'): array
    {
        $staff = $this->createStaffUser();
        Business::current()->update([
            'ai_enabled' => true,
            'ai_customer_responses_enabled' => true,
            'name' => 'ADMAN Test Co',
            'email' => 'hello@adman.test',
        ]);

        $customer = Contact::factory()->customer()->create([
            'email' => 'customer@example.com',
            'whatsapp_id' => '2348011111111',
            'whatsapp_opt_in' => true,
        ]);

        $identity = app(ConversationService::class)->findOrCreateIdentity(
            channel: CommunicationChannel::WhatsApp,
            externalId: '2348011111111',
            displayName: $customer->display_name,
            contact: $customer,
        );

        $conversation = app(ConversationService::class)->openConversation(
            $identity,
            ConversationMode::Ai,
            'AI test',
        );

        $inbound = app(ConversationService::class)->recordInboundMessage($conversation, $body);

        return compact('conversation', 'inbound', 'customer', 'staff') + ['contact' => $customer];
    }

    public function test_ai_settings_permissions(): void
    {
        $staff = $this->createStaffUser();
        $this->actingAs($staff)->get(route('settings.ai.edit'))->assertOk();

        $this->actingAs($staff)->put(route('settings.ai.update'), [
            'enabled' => true,
            'customer_responses_enabled' => true,
        ])->assertRedirect();

        $this->assertTrue(Business::current()->fresh()->ai_enabled);

        $this->seedRolesAndPermissions();
        /** @var User $limited */
        $limited = User::factory()->create();
        $role = Role::findOrCreate('Limited', 'web');
        $role->syncPermissions([Permissions::SETTINGS_ACCESS]);
        $limited->assignRole($role);

        $this->actingAs($limited)->put(route('settings.ai.update'), [
            'enabled' => false,
            'customer_responses_enabled' => false,
        ])->assertForbidden();
    }

    public function test_ai_responds_in_ai_mode_and_not_in_human_or_closed(): void
    {
        ['conversation' => $conversation, 'inbound' => $inbound] = $this->linkedAiConversation('What is your email?');
        FakeAiProvider::respondWith('Our email is hello@adman.test');

        $processing = app(AiService::class)->processInboundMessage($inbound);
        $this->assertSame(AiProcessingStatus::Completed, $processing->status);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'actor_type' => MessageActorType::Ai->value,
            'direction' => MessageDirection::Outbound->value,
        ]);

        $humanInbound = app(ConversationService::class)->recordInboundMessage($conversation, 'Still there?');
        app(ConversationService::class)->takeOver($conversation, $this->createStaffUser());
        $skipped = app(AiService::class)->processInboundMessage($humanInbound);
        $this->assertSame(AiProcessingStatus::Skipped, $skipped->status);

        $closed = app(ConversationService::class)->openConversation(
            $conversation->identity,
            ConversationMode::Ai,
            'Another',
        );
        // Close a fresh conversation path: close then attempt
        app(ConversationService::class)->close($conversation->fresh());
        $closedInbound = Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'channel' => CommunicationChannel::WhatsApp,
            'body' => 'After close',
            'status' => MessageStatus::Delivered,
            'actor_type' => MessageActorType::External,
            'occurred_at' => now(),
        ]);
        $closedProcessing = app(AiService::class)->processInboundMessage($closedInbound);
        $this->assertSame(AiProcessingStatus::Skipped, $closedProcessing->status);
    }

    public function test_unknown_contact_denied_customer_data(): void
    {
        $staff = $this->createStaffUser();
        Business::current()->update(['ai_enabled' => true, 'ai_customer_responses_enabled' => true]);

        $identity = app(ConversationService::class)->findOrCreateIdentity(
            CommunicationChannel::WhatsApp,
            '2348099999999',
            'Unknown',
        );
        $conversation = app(ConversationService::class)->openConversation($identity, ConversationMode::Ai);
        $inbound = app(ConversationService::class)->recordInboundMessage($conversation, 'Show my invoices');

        $auth = app(AiAuthorization::class);
        $this->assertNull($auth->authorizedContact($conversation));

        $result = app(AiToolRegistry::class)->execute('get_customer_invoices', $conversation, $inbound, []);
        $this->assertFalse($result['ok']);
    }

    public function test_authorized_customer_invoice_and_no_cross_customer(): void
    {
        ['conversation' => $conversation, 'inbound' => $inbound, 'contact' => $customer, 'staff' => $staff] = $this->linkedAiConversation();

        $invoice = app(InvoiceService::class)->create([
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff);
        $invoice = app(InvoiceService::class)->issue($invoice);

        $other = Contact::factory()->customer()->create(['email' => 'other@example.com']);
        $otherInvoice = app(InvoiceService::class)->create([
            'contact_id' => $other->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems('50.00'), $staff);
        $otherInvoice = app(InvoiceService::class)->issue($otherInvoice);

        $list = app(AiToolRegistry::class)->execute('get_customer_invoices', $conversation, $inbound, []);
        $this->assertTrue($list['ok']);
        $this->assertSame(1, $list['count']);
        $this->assertSame($invoice->number, $list['invoices'][0]['number']);

        $denied = app(AiToolRegistry::class)->execute('get_invoice', $conversation, $inbound, [
            'invoice_number' => $otherInvoice->number,
        ]);
        $this->assertFalse($denied['ok']);
    }

    public function test_payment_claim_unconfirmed_and_ambiguity(): void
    {
        ['conversation' => $conversation, 'inbound' => $inbound, 'contact' => $customer, 'staff' => $staff] = $this->linkedAiConversation('I paid');

        $first = app(InvoiceService::class)->issue(app(InvoiceService::class)->create([
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems('100.00'), $staff));

        $second = app(InvoiceService::class)->issue(app(InvoiceService::class)->create([
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems('200.00'), $staff));

        $ambiguous = app(AiToolRegistry::class)->execute('create_payment_claim', $conversation, $inbound, [
            'claimed_amount' => '50.00',
        ]);
        $this->assertFalse($ambiguous['ok']);
        $this->assertTrue($ambiguous['needs_clarification'] ?? false);
        $this->assertSame(0, PaymentClaim::query()->count());

        $claimResult = app(AiToolRegistry::class)->execute('create_payment_claim', $conversation, $inbound, [
            'invoice_number' => $first->number,
            'claimed_amount' => '40.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
        ]);
        $this->assertTrue($claimResult['ok']);
        $claim = PaymentClaim::query()->firstOrFail();
        $this->assertSame(PaymentClaimStatus::PendingVerification, $claim->status);
        $this->assertSame('40.00', (string) $claim->claimed_amount);
        $this->assertSame($first->id, $claim->invoice_id);
        $this->assertNotSame(Invoice::query()->find($first->id)->payment_status->value, 'paid');
    }

    public function test_human_handoff_and_return_to_ai(): void
    {
        ['conversation' => $conversation, 'inbound' => $inbound, 'staff' => $staff] = $this->linkedAiConversation('I want to speak to a human');

        FakeAiProvider::reset();
        // Keyword path should hand off without needing tool script.
        $processing = app(AiService::class)->processInboundMessage($inbound);
        $this->assertSame(AiProcessingStatus::Completed, $processing->status);
        $this->assertSame(ConversationMode::Human, $conversation->fresh()->mode);

        app(ConversationService::class)->returnToAi($conversation->fresh());
        $this->assertSame(ConversationMode::Ai, $conversation->fresh()->mode);
    }

    public function test_duplicate_inbound_processing_is_idempotent(): void
    {
        ['inbound' => $inbound] = $this->linkedAiConversation('Ping');
        FakeAiProvider::respondWith('Pong');

        $first = app(AiService::class)->processInboundMessage($inbound);
        $second = app(AiService::class)->processInboundMessage($inbound);

        $this->assertSame(AiProcessingStatus::Completed, $first->status);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Message::query()->where('actor_type', MessageActorType::Ai->value)->count());
    }

    public function test_provider_failure_does_not_send_fabricated_reply(): void
    {
        ['inbound' => $inbound] = $this->linkedAiConversation('Hello');
        FakeAiProvider::script([
            AiProviderResponse::failed('timeout', true),
        ]);

        $processing = app(AiService::class)->processInboundMessage($inbound);
        $this->assertSame(AiProcessingStatus::Failed, $processing->status);
        $this->assertSame(0, Message::query()->where('actor_type', MessageActorType::Ai->value)->count());
    }

    public function test_ai_disabled_skips_processing(): void
    {
        ['inbound' => $inbound] = $this->linkedAiConversation('Hello');
        Business::current()->update(['ai_enabled' => false]);

        $processing = app(AiService::class)->processInboundMessage($inbound);
        $this->assertSame(AiProcessingStatus::Skipped, $processing->status);
    }

    public function test_process_inbound_job_is_dispatchable(): void
    {
        Queue::fake();
        ['inbound' => $inbound] = $this->linkedAiConversation('Queued');

        ProcessInboundAiMessage::dispatch($inbound->id);
        Queue::assertPushed(ProcessInboundAiMessage::class, fn (ProcessInboundAiMessage $job) => $job->inboundMessageId === $inbound->id);
    }
}
