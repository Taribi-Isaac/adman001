<?php

namespace Tests\Feature;

use App\Contracts\EmailDeliveryAdapter;
use App\Contracts\WhatsAppDeliveryAdapter;
use App\Enums\CommunicationChannel;
use App\Enums\DiscountType;
use App\Enums\DocumentType;
use App\Enums\InvoiceLifecycleStatus;
use App\Enums\MessageActorType;
use App\Enums\MessageStatus;
use App\Enums\RecurringBillingDeliveryChannel;
use App\Enums\RecurringBillingDeliveryStatus;
use App\Enums\RecurringBillingFrequency;
use App\Enums\RecurringBillingGenerationStatus;
use App\Jobs\DeliverRecurringInvoice;
use App\Mail\DocumentOutboundMail;
use App\Models\AuditEvent;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\RecurringBillingDelivery;
use App\Models\RecurringBillingGeneration;
use App\Models\RecurringBillingSchedule;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\RecurringBillingService;
use App\Services\RecurringInvoiceDeliveryService;
use App\Services\WhatsAppOutboundService;
use App\Support\EmailDeliveryPayload;
use App\Support\EmailDeliveryResult;
use App\Support\WhatsAppDeliveryPayload;
use App\Support\WhatsAppDeliveryResult;
use App\Support\WhatsAppDocumentPayload;
use App\Support\WhatsAppTextPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\Concerns\OpensWhatsAppServiceWindow;
use Tests\TestCase;

class RecurringInvoiceDeliveryTest extends TestCase
{
    use CreatesFoundationUsers;
    use OpensWhatsAppServiceWindow;
    use RefreshDatabase;

    private object $adapter;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->createSuperAdmin();

        config([
            'adman.whatsapp.enabled' => true,
            'adman.whatsapp.access_token' => 'test-token',
            'adman.whatsapp.phone_number_id' => '123456789',
            'adman.whatsapp.template_language' => 'en',
        ]);
        Business::current()->update(['outbound_whatsapp_enabled' => true, 'email' => 'billing@adman.test']);

        $this->adapter = new class implements WhatsAppDeliveryAdapter
        {
            /** @var list<array{0: string, 1: mixed}> */
            public array $calls = [];

            public function sendTemplate(WhatsAppDeliveryPayload $payload): WhatsAppDeliveryResult
            {
                $this->calls[] = ['template', $payload];

                return WhatsAppDeliveryResult::ok('wamid.TPL');
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

                return WhatsAppDeliveryResult::ok('wamid.DOC');
            }
        };
        $this->app->instance(WhatsAppDeliveryAdapter::class, $this->adapter);
    }

    /**
     * @return list<string>
     */
    private function callKinds(): array
    {
        return array_map(fn (array $call) => $call[0], $this->adapter->calls);
    }

    private function enableInvoiceTemplate(): void
    {
        config(['adman.whatsapp.templates.invoice' => ['name' => 'test_invoice_utility', 'language' => null, 'enabled' => true]]);
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

    private function schedule(RecurringBillingDeliveryChannel $channel, ?Contact $contact = null): RecurringBillingSchedule
    {
        return RecurringBillingSchedule::factory()->create([
            'contact_id' => ($contact ?? $this->customer())->id,
            'delivery_channel' => $channel,
        ]);
    }

    private function generate(RecurringBillingSchedule $schedule): RecurringBillingGeneration
    {
        return app(RecurringBillingService::class)->generateForSchedule($schedule, 'scheduler')->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulePayload(Contact $contact, string $channel, string $price = '100.00'): array
    {
        return [
            'contact_id' => $contact->id,
            'frequency' => RecurringBillingFrequency::Monthly->value,
            'start_date' => now()->toDateString(),
            'payment_term_days' => 14,
            'delivery_channel' => $channel,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
            'items' => [['description' => 'Retainer', 'quantity' => '1', 'unit_price' => $price]],
        ];
    }

    private function delivery(RecurringBillingGeneration $generation, CommunicationChannel $channel): RecurringBillingDelivery
    {
        return RecurringBillingDelivery::query()
            ->where('generation_id', $generation->id)
            ->where('channel', $channel->value)
            ->sole();
    }

    public function test_schedules_default_to_no_delivery_and_still_get_a_pdf(): void
    {
        Mail::fake();
        $schedule = RecurringBillingSchedule::factory()->create();
        $this->assertSame(RecurringBillingDeliveryChannel::None, $schedule->fresh()->delivery_channel);

        $generation = $this->generate($schedule);

        $this->assertSame(RecurringBillingGenerationStatus::Succeeded, $generation->status);
        $this->assertSame(RecurringBillingDeliveryChannel::None, $generation->delivery_channel);
        $this->assertNotNull($generation->document_id);
        $this->assertSame(0, RecurringBillingDelivery::query()->count());
        $this->assertSame(0, Message::query()->count());
        $this->assertSame([], $this->adapter->calls);
        Mail::assertNothingSent();
    }

    public function test_store_without_delivery_channel_defaults_to_none(): void
    {
        $staff = $this->createStaffUser();
        $payload = $this->schedulePayload($this->customer(), 'none');
        unset($payload['delivery_channel']);

        $this->actingAs($staff)->post(route('recurring-billing.store'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(RecurringBillingDeliveryChannel::None, RecurringBillingSchedule::query()->sole()->delivery_channel);
    }

    public function test_pdf_is_generated_without_an_authenticated_user(): void
    {
        $schedule = $this->schedule(RecurringBillingDeliveryChannel::None);
        $this->assertGuest();

        $generation = $this->generate($schedule);

        $document = Document::query()->findOrFail($generation->document_id);
        $this->assertSame(DocumentType::InvoicePdf, $document->type);
        $this->assertSame($generation->invoice_id, $document->documentable_id);
        $this->assertSame($this->owner->id, $document->generated_by);
        $this->assertNull($document->access_token_hash);
        $this->assertTrue(Storage::disk($document->disk)->exists($document->path));
        $this->assertNull($generation->pdf_failure_reason);
        $this->assertDatabaseHas('audit_events', ['event' => 'recurring_billing.invoice_pdf_generated']);
    }

    public function test_email_delivery_sends_the_invoice_pdf_and_records_history(): void
    {
        Mail::fake();
        $schedule = $this->schedule(RecurringBillingDeliveryChannel::Email);

        $generation = $this->generate($schedule);

        $delivery = $this->delivery($generation, CommunicationChannel::Email);
        $this->assertSame(RecurringBillingDeliveryStatus::Queued, $delivery->status);
        $message = Message::query()->findOrFail($delivery->message_id);
        $this->assertSame(CommunicationChannel::Email, $message->channel);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame(MessageActorType::System, $message->actor_type);
        $this->assertSame($generation->document_id, $message->document_id);
        $this->assertSame('invoice', $message->template_key);

        Mail::assertSent(DocumentOutboundMail::class, function (DocumentOutboundMail $mail) {
            return $mail->hasTo('ada@example.com')
                && $mail->payload->hasAttachment()
                && count($mail->attachments()) === 1;
        });
        Mail::assertSent(DocumentOutboundMail::class, 1);
        $this->assertSame(1, Document::query()->count());
        $this->assertDatabaseHas('audit_events', ['event' => 'recurring_billing.delivery_queued']);
        $this->assertSame([], $this->adapter->calls);
    }

    public function test_whatsapp_inside_the_service_window_sends_the_pdf_as_a_document(): void
    {
        $contact = $this->customer();
        $this->recordWhatsAppInboundFrom($contact);
        $schedule = $this->schedule(RecurringBillingDeliveryChannel::WhatsApp, $contact);

        $generation = $this->generate($schedule);

        $message = Message::query()->findOrFail($this->delivery($generation, CommunicationChannel::WhatsApp)->message_id);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame(WhatsAppOutboundService::KIND_DOCUMENT, $message->meta['delivery_kind']);
        $this->assertSame(MessageActorType::System, $message->actor_type);
        $this->assertSame($generation->document_id, $message->document_id);
        $this->assertSame(['upload', 'document'], $this->callKinds());
    }

    public function test_whatsapp_outside_the_window_uses_the_approved_invoice_template(): void
    {
        $this->enableInvoiceTemplate();
        $contact = $this->customer();
        $this->recordWhatsAppInboundFrom($contact, now()->subHours(25));
        $schedule = $this->schedule(RecurringBillingDeliveryChannel::WhatsApp, $contact);

        $generation = $this->generate($schedule);

        $message = Message::query()->findOrFail($this->delivery($generation, CommunicationChannel::WhatsApp)->message_id);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame(WhatsAppOutboundService::KIND_TEMPLATE, $message->meta['delivery_kind']);
        $this->assertSame('test_invoice_utility', $message->meta['template_name']);
        $this->assertSame(['upload', 'template'], $this->callKinds());
    }

    public function test_whatsapp_outside_the_window_without_a_template_is_recorded_and_never_sent_free_form(): void
    {
        $contact = $this->customer();
        $this->recordWhatsAppInboundFrom($contact, now()->subHours(25));
        $schedule = $this->schedule(RecurringBillingDeliveryChannel::WhatsApp, $contact);

        $generation = $this->generate($schedule);

        $delivery = $this->delivery($generation, CommunicationChannel::WhatsApp);
        $this->assertSame(RecurringBillingDeliveryStatus::NotDeliverable, $delivery->status);
        $this->assertNull($delivery->message_id);
        $this->assertNotEmpty($delivery->failure_reason);
        $this->assertSame([], $this->adapter->calls);
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
        $this->assertSame(InvoiceLifecycleStatus::Issued, Invoice::query()->findOrFail($generation->invoice_id)->lifecycle_status);
        $this->assertDatabaseHas('audit_events', ['event' => 'recurring_billing.delivery_failed']);
    }

    public function test_whatsapp_consent_is_checked_again_at_delivery_time(): void
    {
        $contact = $this->customer();
        $schedule = $this->schedule(RecurringBillingDeliveryChannel::WhatsApp, $contact);
        $contact->update(['whatsapp_opt_in' => false]);

        $generation = $this->generate($schedule);

        $this->assertSame(RecurringBillingDeliveryStatus::NotDeliverable, $this->delivery($generation, CommunicationChannel::WhatsApp)->status);
        $this->assertSame([], $this->adapter->calls);
        $this->assertSame(RecurringBillingGenerationStatus::Succeeded, $generation->status);
    }

    public function test_both_channels_are_delivered_independently(): void
    {
        Mail::fake();
        $contact = $this->customer();
        $this->recordWhatsAppInboundFrom($contact);
        $schedule = $this->schedule(RecurringBillingDeliveryChannel::Both, $contact);

        $generation = $this->generate($schedule);

        $this->assertSame(RecurringBillingDeliveryStatus::Queued, $this->delivery($generation, CommunicationChannel::Email)->status);
        $this->assertSame(RecurringBillingDeliveryStatus::Queued, $this->delivery($generation, CommunicationChannel::WhatsApp)->status);
        Mail::assertSent(DocumentOutboundMail::class, 1);
        $this->assertSame(['upload', 'document'], $this->callKinds());
        $this->assertSame(1, Document::query()->count());
    }

    public function test_one_channel_failing_does_not_affect_the_other(): void
    {
        Mail::fake();
        $contact = $this->customer();
        $this->recordWhatsAppInboundFrom($contact);
        $schedule = $this->schedule(RecurringBillingDeliveryChannel::Both, $contact);
        $contact->update(['email' => null]);

        $generation = $this->generate($schedule);

        $this->assertSame(RecurringBillingDeliveryStatus::NotDeliverable, $this->delivery($generation, CommunicationChannel::Email)->status);
        $this->assertSame(RecurringBillingDeliveryStatus::Queued, $this->delivery($generation, CommunicationChannel::WhatsApp)->status);
        $this->assertSame(['upload', 'document'], $this->callKinds());
        Mail::assertNothingSent();
    }

    public function test_provider_failure_is_recorded_on_the_message_and_does_not_block_whatsapp(): void
    {
        $this->app->instance(EmailDeliveryAdapter::class, new class implements EmailDeliveryAdapter
        {
            public function send(EmailDeliveryPayload $payload): EmailDeliveryResult
            {
                return EmailDeliveryResult::failed('Provider rejected the message.');
            }
        });
        $contact = $this->customer();
        $this->recordWhatsAppInboundFrom($contact);
        $schedule = $this->schedule(RecurringBillingDeliveryChannel::Both, $contact);

        $generation = $this->generate($schedule);

        $email = Message::query()->findOrFail($this->delivery($generation, CommunicationChannel::Email)->message_id);
        $this->assertSame(MessageStatus::Failed, $email->status);
        $this->assertNotNull($email->failure_reason);
        $whatsapp = Message::query()->findOrFail($this->delivery($generation, CommunicationChannel::WhatsApp)->message_id);
        $this->assertSame(MessageStatus::Sent, $whatsapp->status);
        $this->assertSame(InvoiceLifecycleStatus::Issued, Invoice::query()->findOrFail($generation->invoice_id)->lifecycle_status);
    }

    public function test_pdf_failure_keeps_the_invoice_issued_and_is_recorded(): void
    {
        Mail::fake();
        $this->mock(DocumentService::class)
            ->shouldReceive('generateInvoicePdf')
            ->andThrow(new RuntimeException('Renderer unavailable.'));
        $schedule = $this->schedule(RecurringBillingDeliveryChannel::Email);

        $generation = $this->generate($schedule);

        $this->assertSame(RecurringBillingGenerationStatus::Succeeded, $generation->status);
        $invoice = Invoice::query()->findOrFail($generation->invoice_id);
        $this->assertSame(InvoiceLifecycleStatus::Issued, $invoice->lifecycle_status);
        $this->assertSame('Renderer unavailable.', $generation->pdf_failure_reason);
        $this->assertNull($generation->document_id);

        $delivery = $this->delivery($generation, CommunicationChannel::Email);
        $this->assertSame(RecurringBillingDeliveryStatus::NotDeliverable, $delivery->status);
        $this->assertStringContainsString('Renderer unavailable.', (string) $delivery->failure_reason);
        Mail::assertNothingSent();
        $this->assertNotNull($schedule->fresh()->next_generation_date);
        $this->assertTrue($schedule->fresh()->next_generation_date->isAfter(now()));
    }

    public function test_retrying_delivery_does_not_send_twice(): void
    {
        Mail::fake();
        $contact = $this->customer();
        $this->recordWhatsAppInboundFrom($contact);
        $schedule = $this->schedule(RecurringBillingDeliveryChannel::Both, $contact);
        $generation = $this->generate($schedule);

        app(RecurringInvoiceDeliveryService::class)->process($generation->id);
        DeliverRecurringInvoice::dispatchSync($generation->id);
        app(RecurringInvoiceDeliveryService::class)->markUndelivered($generation->id, 'Gave up.');

        $this->assertSame(2, RecurringBillingDelivery::query()->count());
        $this->assertSame(0, RecurringBillingDelivery::query()->where('status', RecurringBillingDeliveryStatus::NotDeliverable)->count());
        $this->assertSame(2, Message::query()->where('direction', 'outbound')->count());
        $this->assertSame(1, Document::query()->count());
        Mail::assertSent(DocumentOutboundMail::class, 1);
        $this->assertSame(['upload', 'document'], $this->callKinds());
    }

    public function test_repeated_scheduler_runs_do_not_duplicate_invoices_or_messages(): void
    {
        Mail::fake();
        $contact = $this->customer();
        $this->recordWhatsAppInboundFrom($contact);
        $this->schedule(RecurringBillingDeliveryChannel::Both, $contact);

        Artisan::call('recurring-billing:process-due', ['--sync' => true]);
        Artisan::call('recurring-billing:process-due', ['--sync' => true]);
        DeliverRecurringInvoice::dispatchSync(RecurringBillingGeneration::query()->sole()->id);

        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(1, RecurringBillingGeneration::query()->count());
        $this->assertSame(1, Document::query()->count());
        $this->assertSame(2, Message::query()->where('direction', 'outbound')->count());
        Mail::assertSent(DocumentOutboundMail::class, 1);
        $this->assertSame(['upload', 'document'], $this->callKinds());
    }

    public function test_generation_keeps_the_channel_it_was_created_with(): void
    {
        Mail::fake();
        $schedule = $this->schedule(RecurringBillingDeliveryChannel::Email);
        $generation = $this->generate($schedule);

        $schedule->update(['delivery_channel' => RecurringBillingDeliveryChannel::WhatsApp]);
        app(RecurringInvoiceDeliveryService::class)->process($generation->id);

        $this->assertSame(RecurringBillingDeliveryChannel::Email, $generation->fresh()->delivery_channel);
        $this->assertSame(1, RecurringBillingDelivery::query()->count());
        $this->assertSame([], $this->adapter->calls);
    }

    public function test_email_delivery_requires_a_valid_customer_email(): void
    {
        $staff = $this->createStaffUser();
        $contact = $this->customer(['email' => null]);

        $this->actingAs($staff)
            ->post(route('recurring-billing.store'), $this->schedulePayload($contact, 'email'))
            ->assertSessionHasErrors('delivery_channel');
        $this->actingAs($staff)
            ->post(route('recurring-billing.store'), $this->schedulePayload($contact, 'both'))
            ->assertSessionHasErrors('delivery_channel');

        $this->assertSame(0, RecurringBillingSchedule::query()->count());
    }

    public function test_whatsapp_delivery_requires_consent_and_a_number(): void
    {
        $staff = $this->createStaffUser();
        $noConsent = $this->customer(['whatsapp_opt_in' => false]);
        $noNumber = $this->customer(['whatsapp_id' => null, 'phone' => null]);

        $this->actingAs($staff)
            ->post(route('recurring-billing.store'), $this->schedulePayload($noConsent, 'whatsapp'))
            ->assertSessionHasErrors('delivery_channel');
        $this->actingAs($staff)
            ->post(route('recurring-billing.store'), $this->schedulePayload($noNumber, 'both'))
            ->assertSessionHasErrors('delivery_channel');

        $this->assertSame(0, RecurringBillingSchedule::query()->count());
    }

    public function test_invalid_delivery_channel_is_rejected(): void
    {
        $staff = $this->createStaffUser();

        $this->actingAs($staff)
            ->post(route('recurring-billing.store'), $this->schedulePayload($this->customer(), 'sms'))
            ->assertSessionHasErrors('delivery_channel');
    }

    public function test_update_validates_and_audits_the_delivery_channel(): void
    {
        $staff = $this->createStaffUser();
        $contact = $this->customer(['email' => null]);
        $schedule = $this->schedule(RecurringBillingDeliveryChannel::None, $contact);

        $this->actingAs($staff)
            ->put(route('recurring-billing.update', $schedule), $this->schedulePayload($contact, 'email'))
            ->assertSessionHasErrors('delivery_channel');
        $this->assertSame(RecurringBillingDeliveryChannel::None, $schedule->fresh()->delivery_channel);

        $this->actingAs($staff)
            ->put(route('recurring-billing.update', $schedule), $this->schedulePayload($contact, 'whatsapp'))
            ->assertSessionHasNoErrors();
        $this->assertSame(RecurringBillingDeliveryChannel::WhatsApp, $schedule->fresh()->delivery_channel);

        $audit = AuditEvent::query()->where('event', 'recurring_billing.updated')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('none', $audit->old_values['delivery_channel']);
        $this->assertSame('whatsapp', $audit->new_values['delivery_channel']);
    }

    public function test_zero_total_schedules_are_rejected(): void
    {
        $staff = $this->createStaffUser();
        $contact = $this->customer();

        $this->actingAs($staff)
            ->post(route('recurring-billing.store'), $this->schedulePayload($contact, 'none', '0'))
            ->assertSessionHasErrors('items');

        $fullyDiscounted = $this->schedulePayload($contact, 'none');
        $fullyDiscounted['discount_type'] = DiscountType::Percentage->value;
        $fullyDiscounted['discount_value'] = '100';
        $this->actingAs($staff)
            ->post(route('recurring-billing.store'), $fullyDiscounted)
            ->assertSessionHasErrors('items');

        $this->assertSame(0, RecurringBillingSchedule::query()->count());
    }

    public function test_zero_priced_lines_are_allowed_when_the_total_is_positive(): void
    {
        $staff = $this->createStaffUser();
        $payload = $this->schedulePayload($this->customer(), 'none');
        $payload['items'][] = ['description' => 'Included setup', 'quantity' => '1', 'unit_price' => '0'];

        $this->actingAs($staff)->post(route('recurring-billing.store'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, RecurringBillingSchedule::query()->count());
    }

    public function test_screens_expose_the_delivery_channel_and_history(): void
    {
        Mail::fake();
        $staff = $this->createStaffUser();
        $schedule = $this->schedule(RecurringBillingDeliveryChannel::Email);
        $generation = $this->generate($schedule);

        $this->actingAs($staff)
            ->get(route('recurring-billing.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('deliveryChannelOptions', 4)
                ->where('defaults.delivery_channel', 'none'));

        $this->actingAs($staff)
            ->get(route('recurring-billing.edit', $schedule))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('deliveryChannelOptions', 4)
                ->where('schedule.delivery_channel', 'email'));

        $this->actingAs($staff)
            ->get(route('recurring-billing.show', $schedule))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('schedule.delivery_channel_label', 'Email')
                ->where('generations.0.delivery_channel', 'email')
                ->where('generations.0.document.id', $generation->document_id)
                ->where('generations.0.deliveries.0.channel', 'email')
                ->where('generations.0.deliveries.0.status', 'queued')
                ->where('generations.0.deliveries.0.message.status', 'sent'));
    }
}
