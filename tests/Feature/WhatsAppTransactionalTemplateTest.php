<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppDeliveryAdapter;
use App\Enums\ConsentSource;
use App\Enums\ContactStatus;
use App\Enums\DiscountType;
use App\Enums\MessageStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReminderChannelPreference;
use App\Enums\ReminderOccurrenceStatus;
use App\Models\AuditEvent;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\ReminderOccurrence;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\QuoteService;
use App\Services\ReminderService;
use App\Services\WhatsAppInboundService;
use App\Services\WhatsAppOutboundService;
use App\Support\WhatsAppDeliveryPayload;
use App\Support\WhatsAppDeliveryResult;
use App\Support\WhatsAppDocumentPayload;
use App\Support\WhatsAppTextPayload;
use App\WhatsApp\Adapters\WhatsAppCloudApiAdapter;
use App\WhatsApp\WhatsAppErrorMapper;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\Concerns\OpensWhatsAppServiceWindow;
use Tests\TestCase;

class WhatsAppTransactionalTemplateTest extends TestCase
{
    use CreatesFoundationUsers;
    use OpensWhatsAppServiceWindow;
    use RefreshDatabase;

    private object $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'adman.whatsapp.enabled' => true,
            'adman.whatsapp.access_token' => 'test-token',
            'adman.whatsapp.phone_number_id' => '123456789',
            'adman.whatsapp.template_language' => 'en',
        ]);
        Business::current()->update(['outbound_whatsapp_enabled' => true]);

        $this->adapter = new class implements WhatsAppDeliveryAdapter
        {
            /** @var list<array{0: string, 1: mixed}> */
            public array $calls = [];

            /** @var list<WhatsAppDeliveryResult> */
            public array $templateResults = [];

            private int $sequence = 0;

            public function sendTemplate(WhatsAppDeliveryPayload $payload): WhatsAppDeliveryResult
            {
                $this->calls[] = ['template', $payload];

                return array_shift($this->templateResults) ?? WhatsAppDeliveryResult::ok('wamid.TPL'.++$this->sequence);
            }

            public function sendText(WhatsAppTextPayload $payload): WhatsAppDeliveryResult
            {
                $this->calls[] = ['text', $payload];

                return WhatsAppDeliveryResult::ok('wamid.TXT');
            }

            public function uploadMedia(string $absolutePath, string $mimeType, string $filename): WhatsAppDeliveryResult
            {
                $this->calls[] = ['upload', $filename];

                return WhatsAppDeliveryResult::ok('media.PDF');
            }

            public function sendDocument(WhatsAppDocumentPayload $payload): WhatsAppDeliveryResult
            {
                $this->calls[] = ['document', $payload];

                return WhatsAppDeliveryResult::ok('wamid.DOC'.++$this->sequence);
            }
        };
        $this->app->instance(WhatsAppDeliveryAdapter::class, $this->adapter);
    }

    private function enableTemplate(string $key, string $name, ?string $language = null): void
    {
        config(['adman.whatsapp.templates.'.$key => ['name' => $name, 'language' => $language, 'enabled' => true]]);
    }

    /**
     * @return list<string>
     */
    private function callKinds(): array
    {
        return array_map(fn (array $call) => $call[0], $this->adapter->calls);
    }

    private function templateCall(): WhatsAppDeliveryPayload
    {
        $calls = array_values(array_filter($this->adapter->calls, fn (array $call) => $call[0] === 'template'));
        $this->assertCount(1, $calls);

        return $calls[0][1];
    }

    private function customer(array $attributes = []): Contact
    {
        return Contact::factory()->customer()->create(array_merge([
            'display_name' => 'Ada Obi',
            'whatsapp_id' => '2348012345678',
            'phone' => '+2348012345678',
            'whatsapp_opt_in' => true,
            'email' => 'ada@example.com',
        ], $attributes));
    }

    private function closedWindow(Contact $contact): void
    {
        $this->recordWhatsAppInboundFrom($contact, now()->subHours(25));
    }

    /**
     * @return list<array{description: string, quantity: string, unit_price: string}>
     */
    private function items(): array
    {
        return [['description' => 'Service', 'quantity' => '1', 'unit_price' => '250.00']];
    }

    private function issuedInvoice(Contact $contact, User $staff, ?string $dueDate = null): Invoice
    {
        $service = app(InvoiceService::class);

        return $service->issue($service->create(array_filter([
            'contact_id' => $contact->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
            'due_date' => $dueDate,
        ]), $this->items(), $staff))->fresh(['contact', 'documents']);
    }

    public function test_open_window_uses_document_message_without_template(): void
    {
        $staff = $this->createStaffUser();
        $contact = $this->customer();
        $this->recordWhatsAppInboundFrom($contact);
        $invoice = $this->issuedInvoice($contact, $staff);

        $this->actingAs($staff)->post(route('invoices.send-whatsapp', $invoice))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $message = Message::query()->where('template_key', 'invoice')->sole();
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame(WhatsAppOutboundService::KIND_DOCUMENT, $message->meta['delivery_kind']);
        $this->assertArrayNotHasKey('template_name', $message->meta);
        $this->assertSame(['upload', 'document'], $this->callKinds());
    }

    public function test_closed_window_sends_invoice_quote_and_payment_ack_as_templates_with_pdf_header(): void
    {
        $this->enableTemplate('quote', 'test_quote_utility');
        $this->enableTemplate('invoice', 'test_invoice_utility', 'en_US');
        $this->enableTemplate('payment_acknowledgement', 'test_payment_utility');

        $staff = $this->createStaffUser();
        $contact = $this->customer();
        $this->closedWindow($contact);

        $quoteService = app(QuoteService::class);
        $quote = $quoteService->issue($quoteService->create([
            'contact_id' => $contact->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->items(), $staff));
        $this->actingAs($staff)->post(route('quotes.send-whatsapp', $quote))->assertSessionHasNoErrors();
        $quotePayload = $this->templateCall();
        $this->assertSame('test_quote_utility', $quotePayload->templateName);
        $this->assertSame('en', $quotePayload->languageCode);
        $this->assertSame('media.PDF', $quotePayload->headerDocumentMediaId);
        $this->assertStringEndsWith('.pdf', (string) $quotePayload->headerDocumentFilename);
        $this->assertSame('Ada Obi', $quotePayload->bodyParameters[0]);
        $this->assertSame($quote->number, $quotePayload->bodyParameters[1]);
        $this->assertStringContainsString('/d/', $quotePayload->bodyParameters[2]);
        $this->assertCount(3, $quotePayload->bodyParameters);

        $this->adapter->calls = [];
        $invoice = $this->issuedInvoice($contact, $staff);
        $this->actingAs($staff)->post(route('invoices.send-whatsapp', $invoice))->assertSessionHasNoErrors();
        $invoicePayload = $this->templateCall();
        $this->assertSame('test_invoice_utility', $invoicePayload->templateName);
        $this->assertSame('en_US', $invoicePayload->languageCode);
        $this->assertSame([$contact->display_name, $invoice->number], array_slice($invoicePayload->bodyParameters, 0, 2));

        $this->adapter->calls = [];
        $payment = app(PaymentService::class)->record($invoice, [
            'amount' => '50.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
            'payment_date' => now()->toDateString(),
        ], $staff, true);
        $this->actingAs($staff)->post(route('payments.send-whatsapp', $payment))->assertSessionHasNoErrors();
        $ackPayload = $this->templateCall();
        $this->assertSame('test_payment_utility', $ackPayload->templateName);
        $this->assertSame($payment->number, $ackPayload->bodyParameters[1]);

        $this->assertNotContains('document', $this->callKinds());

        $messages = Message::query()->where('direction', 'outbound')->orderBy('id')->get();
        $this->assertCount(3, $messages);
        foreach ($messages as $message) {
            $this->assertSame(MessageStatus::Sent, $message->status);
            $this->assertSame(WhatsAppOutboundService::KIND_TEMPLATE, $message->meta['delivery_kind']);
            $this->assertNotNull($message->document_id);
        }
        $this->assertSame('test_invoice_utility', $messages[1]->meta['template_name']);
        $this->assertSame(3, AuditEvent::query()->where('event', 'whatsapp.queued')
            ->where('new_values->delivery_kind', WhatsAppOutboundService::KIND_TEMPLATE)->count());
        $this->assertSame(3, AuditEvent::query()->where('event', 'whatsapp.sent')->count());
    }

    public function test_customer_who_never_messaged_counts_as_closed_window(): void
    {
        $this->enableTemplate('invoice', 'test_invoice_utility');
        $staff = $this->createStaffUser();
        $invoice = $this->issuedInvoice($this->customer(), $staff);

        $this->actingAs($staff)->post(route('invoices.send-whatsapp', $invoice))->assertSessionHasNoErrors();

        $this->assertSame(['upload', 'template'], $this->callKinds());
    }

    public function test_closed_window_without_enabled_template_is_blocked_without_sending(): void
    {
        $staff = $this->createStaffUser();
        $contact = $this->customer();
        $this->closedWindow($contact);
        $invoice = $this->issuedInvoice($contact, $staff);

        // Production today: names configured, templates not enabled.
        config(['adman.whatsapp.templates.invoice' => ['name' => 'adman_invoice', 'language' => null, 'enabled' => false]]);

        $this->actingAs($staff)
            ->from(route('invoices.show', $invoice))
            ->post(route('invoices.send-whatsapp', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHasErrors('whatsapp')
            ->assertSessionMissing('success');

        // Enabled but without a name is not usable either.
        $this->enableTemplate('invoice', '');
        $this->actingAs($staff)->post(route('invoices.send-whatsapp', $invoice))->assertSessionHasErrors('whatsapp');

        $this->assertSame([], $this->adapter->calls);
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());

        $blocked = AuditEvent::query()->where('event', 'whatsapp.blocked')->get();
        $this->assertCount(2, $blocked);
        $this->assertSame('service_window_closed_template_unavailable', $blocked[0]->new_values['reason']);
        $this->assertSame('invoice', $blocked[0]->new_values['template']);
        $this->assertSame(Invoice::class, $blocked[0]->auditable_type);
    }

    public function test_reminder_with_closed_window_and_no_template_is_not_deliverable_once(): void
    {
        Business::current()->update(['invoice_reminders_enabled' => true]);
        app(ReminderService::class)->ensureDefaultRules();

        $staff = $this->createStaffUser();
        $contact = $this->customer(['reminder_channel' => ReminderChannelPreference::WhatsApp]);
        $this->closedWindow($contact);
        $due = CarbonImmutable::now(Business::current()->timezone ?: 'UTC')->addDays(7);
        $invoice = $this->issuedInvoice($contact, $staff, $due->toDateString());

        $today = $due->subDays(7);
        $occurrence = app(ReminderService::class)->processDue($today, dispatchJobs: false)->firstWhere('invoice_id', $invoice->id);
        $this->assertNotNull($occurrence);
        app(ReminderService::class)->processOccurrence($occurrence);

        $occurrence->refresh();
        $this->assertSame(ReminderOccurrenceStatus::NotDeliverable, $occurrence->status);
        $this->assertStringContainsString('approved invoice reminder template', (string) $occurrence->failure_reason);
        $this->assertSame([], $this->adapter->calls);
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());

        // Re-running the same day neither re-claims nor re-sends.
        $this->assertTrue(app(ReminderService::class)->processDue($today, dispatchJobs: false)->where('invoice_id', $invoice->id)->isEmpty());
        app(ReminderService::class)->processOccurrence($occurrence);
        $this->assertSame(1, ReminderOccurrence::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame([], $this->adapter->calls);
    }

    public function test_reminder_with_closed_window_uses_reminder_template_parameters(): void
    {
        Business::current()->update(['invoice_reminders_enabled' => true]);
        app(ReminderService::class)->ensureDefaultRules();
        $this->enableTemplate('invoice_reminder', 'test_reminder_utility');

        $staff = $this->createStaffUser();
        $contact = $this->customer(['reminder_channel' => ReminderChannelPreference::WhatsApp]);
        $this->closedWindow($contact);
        $due = CarbonImmutable::now(Business::current()->timezone ?: 'UTC')->addDays(7);
        $invoice = $this->issuedInvoice($contact, $staff, $due->toDateString());

        $occurrence = app(ReminderService::class)->processDue($due->subDays(7), dispatchJobs: false)->firstWhere('invoice_id', $invoice->id);
        app(ReminderService::class)->processOccurrence($occurrence);

        $this->assertSame(ReminderOccurrenceStatus::Queued, $occurrence->fresh()->status);
        $payload = $this->templateCall();
        $this->assertSame('test_reminder_utility', $payload->templateName);
        $this->assertSame('media.PDF', $payload->headerDocumentMediaId);
        $this->assertSame([
            'Ada Obi',
            $invoice->number,
            $invoice->currency_code.' 250.00',
            $invoice->due_date->format('j M Y'),
        ], array_slice($payload->bodyParameters, 0, 4));
        $this->assertStringContainsString('/d/', $payload->bodyParameters[4]);

        $message = Message::query()->where('template_key', 'invoice_reminder')->sole();
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame($occurrence->fresh()->message_id, $message->id);
    }

    public function test_window_closing_before_delivery_switches_to_template_or_fails_safely(): void
    {
        Queue::fake();
        $staff = $this->createStaffUser();
        $contact = $this->customer();
        $conversation = $this->recordWhatsAppInboundFrom($contact);
        $invoice = $this->issuedInvoice($contact, $staff);

        $message = app(WhatsAppOutboundService::class)->queueInvoiceWhatsApp($invoice, $staff);
        $this->assertSame(WhatsAppOutboundService::KIND_DOCUMENT, $message->meta['delivery_kind']);

        Message::query()->where('conversation_id', $conversation->id)->where('direction', 'inbound')
            ->update(['occurred_at' => now()->subHours(30)]);

        // No template: fails without calling the provider and without retry.
        app(WhatsAppOutboundService::class)->deliverQueuedMessage($message);
        $message->refresh();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertStringContainsString('approved invoice template', (string) $message->failure_reason);
        $this->assertSame([], $this->adapter->calls);
        $failed = AuditEvent::query()->where('event', 'whatsapp.failed')->latest('id')->first();
        $this->assertFalse($failed->meta['retryable']);

        // Retry is refused while nothing valid can be sent.
        try {
            app(WhatsAppOutboundService::class)->retry($message, $staff);
            $this->fail('Retry should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('message', $e->errors());
        }

        // Once an approved template is enabled, a retry is sent as a template.
        $this->enableTemplate('invoice', 'test_invoice_utility');
        app(WhatsAppOutboundService::class)->retry($message, $staff);
        app(WhatsAppOutboundService::class)->deliverQueuedMessage($message->fresh());

        $message->refresh();
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame(WhatsAppOutboundService::KIND_TEMPLATE, $message->meta['delivery_kind']);
        $this->assertSame('test_invoice_utility', $message->meta['template_name']);
        $this->assertSame(['upload', 'template'], $this->callKinds());
    }

    public function test_window_reopening_before_delivery_uses_document_message(): void
    {
        Queue::fake();
        $this->enableTemplate('invoice', 'test_invoice_utility');
        $staff = $this->createStaffUser();
        $contact = $this->customer();
        $this->closedWindow($contact);
        $invoice = $this->issuedInvoice($contact, $staff);

        $message = app(WhatsAppOutboundService::class)->queueInvoiceWhatsApp($invoice, $staff);
        $this->assertSame(WhatsAppOutboundService::KIND_TEMPLATE, $message->meta['delivery_kind']);

        $this->recordWhatsAppInboundFrom($contact, now()->subMinutes(5));
        app(WhatsAppOutboundService::class)->deliverQueuedMessage($message);

        $message->refresh();
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame(WhatsAppOutboundService::KIND_DOCUMENT, $message->meta['delivery_kind']);
        $this->assertArrayNotHasKey('template_name', $message->meta);
        $this->assertSame(['upload', 'document'], $this->callKinds());
    }

    public function test_cloud_api_template_request_carries_document_header_and_parameters(): void
    {
        $this->app->forgetInstance(WhatsAppDeliveryAdapter::class);
        $this->app->bind(WhatsAppDeliveryAdapter::class, WhatsAppCloudApiAdapter::class);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/media')) {
                return Http::response(['id' => 'media.REAL'], 200);
            }

            return Http::response(['messages' => [['id' => 'wamid.REALTPL']]], 200);
        });
        $this->enableTemplate('invoice', 'test_invoice_utility', 'en_GB');

        $staff = $this->createStaffUser();
        $contact = $this->customer(['display_name' => "Ada\n  Obi"]);
        $this->closedWindow($contact);
        $invoice = $this->issuedInvoice($contact, $staff);

        $this->actingAs($staff)->post(route('invoices.send-whatsapp', $invoice))->assertSessionHasNoErrors();

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->first(fn (Request $request) => str_ends_with($request->url(), '/messages'));
        $this->assertNotNull($sent);
        $body = $sent->data();
        $this->assertSame('template', $body['type']);
        $this->assertSame('2348012345678', $body['to']);
        $this->assertSame('test_invoice_utility', $body['template']['name']);
        $this->assertSame('en_GB', $body['template']['language']['code']);
        $this->assertSame('header', $body['template']['components'][0]['type']);
        $this->assertSame('document', $body['template']['components'][0]['parameters'][0]['type']);
        $this->assertSame('media.REAL', $body['template']['components'][0]['parameters'][0]['document']['id']);
        $this->assertStringEndsWith('.pdf', $body['template']['components'][0]['parameters'][0]['document']['filename']);
        $this->assertSame('body', $body['template']['components'][1]['type']);
        $this->assertSame('Ada Obi', $body['template']['components'][1]['parameters'][0]['text']);
        $this->assertSame($invoice->number, $body['template']['components'][1]['parameters'][1]['text']);

        $this->assertSame('wamid.REALTPL', Message::query()->where('template_key', 'invoice')->sole()->external_message_id);
    }

    public function test_template_rejected_by_meta_is_recorded_as_failed_without_retry(): void
    {
        $this->app->forgetInstance(WhatsAppDeliveryAdapter::class);
        $this->app->bind(WhatsAppDeliveryAdapter::class, WhatsAppCloudApiAdapter::class);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/media')) {
                return Http::response(['id' => 'media.REAL'], 200);
            }

            return Http::response(['error' => ['code' => 132001, 'message' => 'Template name does not exist in the translation']], 400);
        });
        $this->enableTemplate('invoice', 'test_invoice_utility');

        $staff = $this->createStaffUser();
        $contact = $this->customer();
        $this->closedWindow($contact);
        $invoice = $this->issuedInvoice($contact, $staff);

        $this->actingAs($staff)->post(route('invoices.send-whatsapp', $invoice))->assertSessionHasNoErrors();

        $message = Message::query()->where('template_key', 'invoice')->sole();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertNull($message->external_message_id);
        $this->assertStringContainsString('template does not exist', (string) $message->failure_reason);
        $failed = AuditEvent::query()->where('event', 'whatsapp.failed')->sole();
        $this->assertFalse($failed->meta['retryable']);
        $this->assertSame(0, AuditEvent::query()->where('event', 'whatsapp.sent')->count());
    }

    public function test_transient_provider_failure_is_retryable_and_delivery_is_idempotent(): void
    {
        Queue::fake();
        $this->enableTemplate('invoice', 'test_invoice_utility');
        $staff = $this->createStaffUser();
        $contact = $this->customer();
        $this->closedWindow($contact);
        $invoice = $this->issuedInvoice($contact, $staff);
        $message = app(WhatsAppOutboundService::class)->queueInvoiceWhatsApp($invoice, $staff);

        $this->adapter->templateResults = [WhatsAppDeliveryResult::failed('WhatsApp rate limit reached. Please retry later.', retryable: true)];

        try {
            app(WhatsAppOutboundService::class)->deliverQueuedMessage($message);
            $this->fail('Transient failure should be rethrown for the queue to retry.');
        } catch (\RuntimeException) {
        }
        $this->assertSame(MessageStatus::Failed, $message->fresh()->status);

        app(WhatsAppOutboundService::class)->deliverQueuedMessage($message->fresh());
        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);

        $templateSends = count(array_filter($this->callKinds(), fn ($kind) => $kind === 'template'));
        app(WhatsAppOutboundService::class)->deliverQueuedMessage($message->fresh());
        $this->assertSame($templateSends, count(array_filter($this->callKinds(), fn ($kind) => $kind === 'template')));
    }

    public function test_status_webhook_failure_uses_clear_reason(): void
    {
        $staff = $this->createStaffUser();
        $contact = $this->customer();
        $this->recordWhatsAppInboundFrom($contact);
        $invoice = $this->issuedInvoice($contact, $staff);
        $this->actingAs($staff)->post(route('invoices.send-whatsapp', $invoice));
        $message = Message::query()->where('template_key', 'invoice')->sole();

        app(WhatsAppInboundService::class)->handlePayload([
            'entry' => [['changes' => [['value' => ['statuses' => [[
                'id' => $message->external_message_id,
                'status' => 'failed',
                'timestamp' => (string) time(),
                'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
            ]]]]]]],
        ]);

        $message->refresh();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertStringContainsString('24-hour customer service window has closed', (string) $message->failure_reason);
    }

    public function test_error_mapper_distinguishes_operational_failures(): void
    {
        $this->assertStringContainsString('window has closed', WhatsAppErrorMapper::reason(400, '131047'));
        $this->assertStringContainsString('does not exist', WhatsAppErrorMapper::reason(400, '132001'));
        $this->assertStringContainsString('paused', WhatsAppErrorMapper::reason(400, '132015'));
        $this->assertStringContainsString('disabled', WhatsAppErrorMapper::reason(400, '132016'));
        $this->assertStringContainsString('stopped receiving', WhatsAppErrorMapper::reason(400, '131050'));
        $this->assertStringContainsString('payment', WhatsAppErrorMapper::reason(400, '131042'));
        $this->assertStringContainsString('locked or restricted', WhatsAppErrorMapper::reason(403, '131031'));
        $this->assertStringContainsString('authentication', WhatsAppErrorMapper::reason(401, null));
        $this->assertStringContainsString('rejected the message', WhatsAppErrorMapper::reason(400, '100'));

        $this->assertFalse(WhatsAppErrorMapper::retryable(400, '132001'));
        $this->assertFalse(WhatsAppErrorMapper::retryable(400, '131047'));
        $this->assertTrue(WhatsAppErrorMapper::retryable(400, '130429'));
        $this->assertTrue(WhatsAppErrorMapper::retryable(503, null));
    }

    public function test_broadcast_opt_out_does_not_block_template_send_and_whatsapp_opt_in_still_gates(): void
    {
        $this->enableTemplate('invoice', 'test_invoice_utility');
        $staff = $this->createStaffUser();
        $contact = $this->customer([
            'whatsapp_broadcast_opt_out_at' => now(),
            'whatsapp_broadcast_opt_out_source' => ConsentSource::WhatsApp,
            'email_broadcast_unsubscribed_at' => now(),
            'email_broadcast_unsubscribe_source' => ConsentSource::Other,
        ]);
        $this->closedWindow($contact);
        $invoice = $this->issuedInvoice($contact, $staff);
        $paymentStatus = $invoice->payment_status;

        $this->actingAs($staff)->post(route('invoices.send-whatsapp', $invoice))->assertSessionHasNoErrors();
        $this->assertSame(MessageStatus::Sent, Message::query()->where('template_key', 'invoice')->sole()->status);

        $contact->refresh();
        $this->assertNotNull($contact->whatsapp_broadcast_opt_out_at);
        $this->assertSame(ContactStatus::Customer, $contact->status);
        $this->assertSame($paymentStatus, $invoice->fresh()->payment_status);

        $notOptedIn = $this->customer([
            'whatsapp_id' => '2348099999999',
            'phone' => null,
            'email' => 'no-optin@example.com',
            'whatsapp_opt_in' => false,
        ]);
        $other = $this->issuedInvoice($notOptedIn, $staff);
        $this->actingAs($staff)->post(route('invoices.send-whatsapp', $other))->assertSessionHasErrors('whatsapp');
        $this->assertSame(1, Message::query()->where('direction', 'outbound')->count());
    }

    public function test_payment_acknowledgement_template_does_not_change_payment_or_invoice(): void
    {
        $this->enableTemplate('payment_acknowledgement', 'test_payment_utility');
        $staff = $this->createStaffUser();
        $contact = $this->customer();
        $this->closedWindow($contact);
        $invoice = $this->issuedInvoice($contact, $staff);
        $payment = app(PaymentService::class)->record($invoice, [
            'amount' => '100.00',
            'payment_method' => PaymentMethod::Cash->value,
            'payment_date' => now()->toDateString(),
        ], $staff, true);
        $invoiceBefore = $invoice->fresh()->only(['payment_status', 'balance_due', 'amount_paid', 'lifecycle_status']);

        $this->actingAs($staff)->post(route('payments.send-whatsapp', $payment))->assertSessionHasNoErrors();

        $this->assertSame(PaymentStatus::Confirmed, $payment->fresh()->status);
        $this->assertEquals($invoiceBefore, $invoice->fresh()->only(['payment_status', 'balance_due', 'amount_paid', 'lifecycle_status']));
        $this->assertSame('test_payment_utility', $this->templateCall()->templateName);
    }

    public function test_communication_settings_page_reports_template_state(): void
    {
        $admin = $this->createSuperAdmin();
        $this->enableTemplate('invoice', 'test_invoice_utility');

        $this->actingAs($admin)->get(route('settings.communication.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('whatsapp_templates.1.key', 'invoice')
                ->where('whatsapp_templates.1.configured_name', 'test_invoice_utility')
                ->where('whatsapp_templates.1.enabled', true)
                ->where('whatsapp_templates.0.enabled', false));
    }
}
