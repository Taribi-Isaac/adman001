<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppDeliveryAdapter;
use App\Enums\CommunicationChannel;
use App\Enums\ConversationMode;
use App\Enums\DiscountType;
use App\Enums\MessageStatus;
use App\Enums\PaymentMethod;
use App\Jobs\SendOutboundWhatsAppJob;
use App\Models\Business;
use App\Models\CommunicationIdentity;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\QuoteService;
use App\Services\WhatsAppInboundService;
use App\Services\WhatsAppOutboundService;
use App\Support\Permissions;
use App\Support\WhatsAppDeliveryPayload;
use App\Support\WhatsAppDeliveryResult;
use App\Support\WhatsAppDocumentPayload;
use App\Support\WhatsAppPhone;
use App\Support\WhatsAppTextPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\TestCase;

class WhatsAppCommunicationTest extends TestCase
{
    use CreatesFoundationUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'adman.whatsapp.enabled' => true,
            'adman.whatsapp.access_token' => 'test-token',
            'adman.whatsapp.phone_number_id' => '123456789',
            'adman.whatsapp.app_secret' => 'test-app-secret',
            'adman.whatsapp.webhook_verify_token' => 'verify-me',
            'adman.whatsapp.templates.quote' => 'adman_quote',
            'adman.whatsapp.templates.invoice' => 'adman_invoice',
            'adman.whatsapp.templates.payment_acknowledgement' => 'adman_payment_ack',
        ]);

        Business::current()->update(['outbound_whatsapp_enabled' => true]);
    }

    /**
     * @return list<array{description: string, quantity: string, unit_price: string, unit?: string|null}>
     */
    private function sampleItems(): array
    {
        return [
            [
                'description' => 'Consulting',
                'quantity' => '1',
                'unit' => 'hrs',
                'unit_price' => '100.00',
            ],
        ];
    }

    private function customerReadyForWhatsApp(string $wa = '2348012345678'): Contact
    {
        return Contact::factory()->customer()->create([
            'whatsapp_id' => $wa,
            'whatsapp_opt_in' => true,
            'phone' => '+'.$wa,
            'display_name' => 'WA Customer',
        ]);
    }

    /**
     * @return array{invoice: Invoice, staff: User}
     */
    private function issuedInvoiceForWhatsApp(): array
    {
        $staff = $this->createStaffUser();
        $customer = $this->customerReadyForWhatsApp();
        $invoice = app(InvoiceService::class)->create([
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff);
        $invoice = app(InvoiceService::class)->issue($invoice);

        return ['invoice' => $invoice->fresh(['contact', 'documents']), 'staff' => $staff];
    }

    public function test_whatsapp_phone_normalization_and_identity_reuse(): void
    {
        $this->assertSame('2348012345678', WhatsAppPhone::normalize('+234 801 234 5678'));
        $this->assertSame('2348012345678', WhatsAppPhone::normalize('002348012345678'));

        $service = app(ConversationService::class);
        $contact = $this->customerReadyForWhatsApp();

        $first = $service->findOrCreateIdentity(
            CommunicationChannel::WhatsApp,
            '+2348012345678',
            $contact->display_name,
            $contact,
        );
        $second = $service->findOrCreateIdentity(
            CommunicationChannel::WhatsApp,
            '2348012345678',
            $contact->display_name,
            $contact,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame('2348012345678', $first->external_id);
        $this->assertSame(1, CommunicationIdentity::query()->where('channel', 'whatsapp')->count());
    }

    public function test_authorized_staff_can_queue_invoice_whatsapp(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/media')) {
                return Http::response(['id' => 'media.TESTUPLOAD'], 200);
            }

            return Http::response([
                'messages' => [['id' => 'wamid.TEST123']],
            ], 200);
        });

        ['invoice' => $invoice, 'staff' => $staff] = $this->issuedInvoiceForWhatsApp();

        $this->actingAs($staff)
            ->post(route('invoices.send-whatsapp', $invoice))
            ->assertRedirect()
            ->assertSessionHas('success');

        $message = Message::query()->where('channel', 'whatsapp')->first();
        $this->assertNotNull($message);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame('wamid.TEST123', $message->external_message_id);
        $this->assertSame('document_pdf', $message->meta['delivery_kind'] ?? null);
        $this->assertStringContainsString('/d/', (string) ($message->meta['secure_url'] ?? ''));

        $this->assertDatabaseHas('audit_events', ['event' => 'whatsapp.queued']);
        $this->assertDatabaseHas('audit_events', ['event' => 'whatsapp.sent']);
    }

    public function test_unauthorized_user_cannot_send_whatsapp(): void
    {
        $this->seedRolesAndPermissions();
        ['invoice' => $invoice] = $this->issuedInvoiceForWhatsApp();

        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::findOrCreate('Limited', 'web');
        $role->syncPermissions([Permissions::INVOICES_VIEW, Permissions::SETTINGS_ACCESS]);
        $user->assignRole($role);

        $this->actingAs($user)
            ->post(route('invoices.send-whatsapp', $invoice))
            ->assertForbidden();
    }

    public function test_opt_in_and_draft_rules_block_send(): void
    {
        $staff = $this->createStaffUser();
        $noOptIn = Contact::factory()->customer()->create([
            'whatsapp_id' => '2348099999999',
            'whatsapp_opt_in' => false,
        ]);
        $invoice = app(InvoiceService::class)->create([
            'contact_id' => $noOptIn->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff);
        $invoice = app(InvoiceService::class)->issue($invoice);

        $this->actingAs($staff)
            ->from(route('invoices.show', $invoice))
            ->post(route('invoices.send-whatsapp', $invoice))
            ->assertRedirect()
            ->assertSessionHasErrors('whatsapp');

        $customer = $this->customerReadyForWhatsApp('2348088888888');
        $draft = app(InvoiceService::class)->create([
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff);

        $this->actingAs($staff)
            ->from(route('invoices.show', $draft))
            ->post(route('invoices.send-whatsapp', $draft))
            ->assertRedirect()
            ->assertSessionHasErrors('lifecycle_status');
    }

    public function test_job_is_dispatched_and_provider_failure_is_recorded(): void
    {
        ['invoice' => $invoice, 'staff' => $staff] = $this->issuedInvoiceForWhatsApp();

        Queue::fake();
        $message = app(WhatsAppOutboundService::class)->queueInvoiceWhatsApp($invoice, $staff);
        Queue::assertPushed(SendOutboundWhatsAppJob::class, fn (SendOutboundWhatsAppJob $job) => $job->messageId === $message->id);
        $this->assertSame(MessageStatus::Pending, $message->fresh()->status);

        $this->app->forgetInstance(WhatsAppOutboundService::class);
        $this->app->instance(WhatsAppDeliveryAdapter::class, new class implements WhatsAppDeliveryAdapter
        {
            public function sendTemplate(WhatsAppDeliveryPayload $payload): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::failed('Provider rejected the message.', false);
            }

            public function sendText(WhatsAppTextPayload $payload): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::failed('Provider rejected the message.', false);
            }

            public function uploadMedia(string $absolutePath, string $mimeType, string $filename): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::failed('Provider rejected the message.', false);
            }

            public function sendDocument(WhatsAppDocumentPayload $payload): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::failed('Provider rejected the message.', false);
            }
        });

        app(WhatsAppOutboundService::class)->deliverQueuedMessage($message->fresh());
        $this->assertSame(MessageStatus::Failed, $message->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['event' => 'whatsapp.failed']);

        $this->app->forgetInstance(WhatsAppOutboundService::class);
        $this->app->instance(WhatsAppDeliveryAdapter::class, new class implements WhatsAppDeliveryAdapter
        {
            public function sendTemplate(WhatsAppDeliveryPayload $payload): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::ok('wamid.RETRY');
            }

            public function sendText(WhatsAppTextPayload $payload): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::ok('wamid.RETRY');
            }

            public function uploadMedia(string $absolutePath, string $mimeType, string $filename): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::ok('media.RETRY');
            }

            public function sendDocument(WhatsAppDocumentPayload $payload): WhatsAppDeliveryResult
            {
                return WhatsAppDeliveryResult::ok('wamid.RETRY');
            }
        });

        app(WhatsAppOutboundService::class)->retry($message->fresh(), $staff);
        app(WhatsAppOutboundService::class)->deliverQueuedMessage($message->fresh());

        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);
        $this->assertSame(1, Message::query()->where('channel', 'whatsapp')->count());
    }

    public function test_webhook_verification_and_invalid_signature(): void
    {
        $this->get(route('webhooks.whatsapp.verify', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'verify-me',
            'hub_challenge' => 'challenge-token',
        ]))->assertOk()->assertSee('challenge-token');

        $this->get(route('webhooks.whatsapp.verify', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'wrong',
            'hub_challenge' => 'challenge-token',
        ]))->assertForbidden();

        $this->postJson(route('webhooks.whatsapp.handle'), ['object' => 'whatsapp_business_account'])
            ->assertForbidden();
    }

    public function test_inbound_webhook_creates_unknown_identity_and_is_idempotent(): void
    {
        $payload = $this->sampleInboundPayload('wamid.INBOUND1', '2348077777777', 'Hello ADMAN');
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-app-secret');

        $this->call(
            'POST',
            route('webhooks.whatsapp.handle'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $body,
        )->assertOk();

        $identity = CommunicationIdentity::query()->where('external_id', '2348077777777')->first();
        $this->assertNotNull($identity);
        $this->assertNull($identity->contact_id);

        $conversation = Conversation::query()->where('communication_identity_id', $identity->id)->first();
        $this->assertNotNull($conversation);
        $this->assertSame(ConversationMode::Human, $conversation->mode);

        $this->assertSame(1, Message::query()->where('external_message_id', 'wamid.INBOUND1')->count());

        // Duplicate webhook
        $this->call(
            'POST',
            route('webhooks.whatsapp.handle'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $body,
        )->assertOk();

        $this->assertSame(1, Message::query()->where('external_message_id', 'wamid.INBOUND1')->count());
        $this->assertSame(0, Contact::query()->where('whatsapp_id', '2348077777777')->count());
    }

    public function test_inbound_links_known_contact_and_status_updates_are_forward_only(): void
    {
        $contact = $this->customerReadyForWhatsApp('2348066666666');
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/media')) {
                return Http::response(['id' => 'media.OUT1'], 200);
            }

            return Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200);
        });

        ['invoice' => $invoice, 'staff' => $staff] = $this->issuedInvoiceForWhatsApp();
        // Re-issue path with known contact: send using contact above
        $invoice = app(InvoiceService::class)->create([
            'contact_id' => $contact->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff);
        $invoice = app(InvoiceService::class)->issue($invoice);
        $message = app(WhatsAppOutboundService::class)->queueInvoiceWhatsApp($invoice->fresh(['contact', 'documents']), $staff);
        $this->assertSame('wamid.OUT1', $message->fresh()->external_message_id);

        $inbound = $this->sampleInboundPayload('wamid.IN2', '2348066666666', 'Thanks');
        app(WhatsAppInboundService::class)->handlePayload($inbound);

        $identity = CommunicationIdentity::query()->where('external_id', '2348066666666')->first();
        $this->assertNotNull($identity);
        $this->assertSame($contact->id, $identity->contact_id);

        app(WhatsAppInboundService::class)->handlePayload([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'statuses' => [[
                            'id' => 'wamid.OUT1',
                            'status' => 'delivered',
                            'timestamp' => (string) time(),
                        ]],
                    ],
                ]],
            ]],
        ]);
        $this->assertSame(MessageStatus::Delivered, $message->fresh()->status);

        app(WhatsAppInboundService::class)->handlePayload([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'statuses' => [[
                            'id' => 'wamid.OUT1',
                            'status' => 'read',
                            'timestamp' => (string) time(),
                        ]],
                    ],
                ]],
            ]],
        ]);
        $this->assertSame(MessageStatus::Read, $message->fresh()->status);

        // Duplicate delivered is harmless (no regress from read)
        app(WhatsAppInboundService::class)->handlePayload([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'statuses' => [[
                            'id' => 'wamid.OUT1',
                            'status' => 'delivered',
                            'timestamp' => (string) time(),
                        ]],
                    ],
                ]],
            ]],
        ]);
        $this->assertSame(MessageStatus::Read, $message->fresh()->status);
    }

    public function test_human_mode_does_not_auto_reply_on_inbound(): void
    {
        $payload = $this->sampleInboundPayload('wamid.IN3', '2348055555555', 'Need help');
        app(WhatsAppInboundService::class)->handlePayload($payload);

        $this->assertSame(1, Message::query()->count());
        $this->assertSame(MessageStatus::Delivered, Message::query()->first()->status);
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
    }

    public function test_quote_and_payment_whatsapp_include_secure_link(): void
    {
        $ids = ['wamid.QUOTE1', 'wamid.PAY1'];
        Http::fake(function (Request $request) use (&$ids) {
            if (str_contains($request->url(), '/media')) {
                return Http::response(['id' => 'media.'.(count($ids) ?: 'X')], 200);
            }

            $id = array_shift($ids) ?: 'wamid.FALLBACK';

            return Http::response(['messages' => [['id' => $id]]], 200);
        });

        $staff = $this->createStaffUser();
        $customer = $this->customerReadyForWhatsApp('2348044444444');
        $quote = app(QuoteService::class)->create([
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff);
        $quote = app(QuoteService::class)->issue($quote);

        $this->actingAs($staff)->post(route('quotes.send-whatsapp', $quote))->assertRedirect();
        $quoteMsg = Message::query()->where('template_key', 'quote')->first();
        $this->assertNotNull($quoteMsg);
        $this->assertSame('document_pdf', $quoteMsg->meta['delivery_kind'] ?? null);
        $this->assertStringContainsString('/d/', (string) ($quoteMsg->meta['secure_url'] ?? ''));

        ['invoice' => $invoice, 'staff' => $staff2] = $this->issuedInvoiceForWhatsApp();
        $payment = app(PaymentService::class)->record($invoice, [
            'amount' => $invoice->total,
            'payment_method' => PaymentMethod::BankTransfer->value,
            'payment_date' => now()->toDateString(),
        ], $staff2, true);

        $this->actingAs($staff2)
            ->post(route('payments.send-whatsapp', $payment->fresh(['contact', 'documents'])))
            ->assertRedirect();
        $ack = Message::query()->where('template_key', 'payment_acknowledgement')->first();
        $this->assertNotNull($ack);
        $this->assertSame('document_pdf', $ack->meta['delivery_kind'] ?? null);
    }

    public function test_closed_conversation_stays_closed_and_new_inbound_opens_new_thread(): void
    {
        $service = app(ConversationService::class);
        $identity = $service->findOrCreateIdentity(CommunicationChannel::WhatsApp, '2348033333333', 'Guest');
        $conversation = $service->openConversation($identity, ConversationMode::Human, 'Old');
        $service->close($conversation);

        app(WhatsAppInboundService::class)->handlePayload(
            $this->sampleInboundPayload('wamid.IN4', '2348033333333', 'Back again'),
        );

        $this->assertTrue($conversation->fresh()->isClosed());
        $open = Conversation::query()
            ->where('communication_identity_id', $identity->id)
            ->where('mode', '!=', ConversationMode::Closed->value)
            ->count();
        $this->assertSame(1, $open);
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleInboundPayload(string $messageId, string $from, string $text): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'BUSINESS_ACCOUNT',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '15550001111',
                            'phone_number_id' => '123456789',
                        ],
                        'contacts' => [[
                            'profile' => ['name' => 'Inbound User'],
                            'wa_id' => $from,
                        ]],
                        'messages' => [[
                            'from' => $from,
                            'id' => $messageId,
                            'timestamp' => (string) time(),
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];
    }
}
