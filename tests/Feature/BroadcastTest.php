<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppDeliveryAdapter;
use App\Enums\BroadcastIneligibilityReason;
use App\Enums\BroadcastRecipientStatus;
use App\Enums\BroadcastStatus;
use App\Enums\ConsentSource;
use App\Enums\DiscountType;
use App\Enums\EmailTemplateKey;
use App\Enums\MessageStatus;
use App\Enums\WhatsAppTemplateKey;
use App\Jobs\ProcessBroadcastJob;
use App\Jobs\SendOutboundWhatsAppJob;
use App\Mail\DocumentOutboundMail;
use App\Models\AuditEvent;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Payment;
use App\Models\PaymentClaim;
use App\Models\Quote;
use App\Models\ReminderOccurrence;
use App\Models\User;
use App\Services\BroadcastService;
use App\Services\EmailOutboundService;
use App\Services\InvoiceService;
use App\Services\WhatsAppOutboundService;
use App\Support\EmailDeliveryPayload;
use App\Support\Permissions;
use App\Support\WhatsAppBroadcastTemplate;
use App\Support\WhatsAppDeliveryPayload;
use App\Support\WhatsAppDeliveryResult;
use App\Support\WhatsAppDocumentPayload;
use App\Support\WhatsAppTextPayload;
use App\WhatsApp\Adapters\WhatsAppCloudApiAdapter;
use App\WhatsApp\WhatsAppErrorMapper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\TestCase;

class BroadcastTest extends TestCase
{
    use CreatesFoundationUsers;
    use RefreshDatabase;

    private object $adapter;

    private int $phoneSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'adman.whatsapp.enabled' => true,
            'adman.whatsapp.access_token' => 'test-token',
            'adman.whatsapp.phone_number_id' => '123456789',
            'adman.whatsapp.template_language' => 'en',
            'adman.email.enabled' => true,
            'adman.broadcasts.recipient_limit' => 500,
            'adman.broadcasts.batch_size' => 20,
            'adman.broadcasts.batch_delay_seconds' => 0,
        ]);

        Business::current()->update([
            'outbound_whatsapp_enabled' => true,
            'outbound_email_enabled' => true,
            'email' => 'hello@adman.test',
            'broadcasts_enabled' => true,
        ]);

        Mail::fake();

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

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function enableBroadcastTemplate(string $name = 'adman_promo_test', string $parameters = ''): void
    {
        config(['adman.whatsapp.broadcast_template' => [
            'name' => $name,
            'language' => 'en',
            'enabled' => true,
            'parameters' => $parameters,
        ]]);
    }

    private function whatsappContact(array $attributes = [], string $state = 'customer'): Contact
    {
        $number = '23480100000'.str_pad((string) ++$this->phoneSequence, 2, '0', STR_PAD_LEFT);

        return Contact::factory()->{$state}()->create(array_merge([
            'display_name' => 'WA Contact '.$this->phoneSequence,
            'whatsapp_id' => $number,
            'phone' => '+'.$number,
            'email' => null,
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_at' => now()->subDay(),
            'whatsapp_opt_in_source' => ConsentSource::InPerson,
            'whatsapp_broadcast_opt_in_at' => now()->subDay(),
            'whatsapp_broadcast_opt_in_source' => ConsentSource::InPerson,
        ], $attributes));
    }

    private function emailContact(array $attributes = [], string $state = 'customer'): Contact
    {
        $n = ++$this->phoneSequence;

        return Contact::factory()->{$state}()->create(array_merge([
            'display_name' => 'Email Contact '.$n,
            'email' => "contact{$n}@example.com",
            'whatsapp_id' => null,
            'phone' => null,
            'whatsapp_opt_in' => false,
            'email_broadcast_opt_in_at' => now()->subDay(),
            'email_broadcast_opt_in_source' => ConsentSource::Website,
        ], $attributes));
    }

    private function draft(User $owner, array $attributes = []): Broadcast
    {
        return app(BroadcastService::class)->create(array_merge([
            'name' => 'October offer',
            'channel' => 'email',
            'audience_type' => 'customers',
            'subject' => 'Our October offer',
            'body' => "Hello!\nWe have a new offer.",
        ], $attributes), $owner);
    }

    /**
     * @return list<int>
     */
    private function eligibleIds(Broadcast $broadcast): array
    {
        return array_map(fn (Contact $c) => $c->id, app(BroadcastService::class)->preview($broadcast)['eligible']);
    }

    private function startNow(Broadcast $broadcast, User $owner): Broadcast
    {
        $count = app(BroadcastService::class)->preview($broadcast)['eligible_count'];

        return app(BroadcastService::class)->start($broadcast, $owner, $count);
    }

    /**
     * @return list<WhatsAppDeliveryPayload>
     */
    private function templateCalls(): array
    {
        return array_values(array_map(
            fn (array $call) => $call[1],
            array_filter($this->adapter->calls, fn (array $call) => $call[0] === 'template'),
        ));
    }

    private function staffWith(string ...$permissions): User
    {
        $user = $this->createStaffUser();
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    // ---------------------------------------------------------------------
    // Authorization and creation
    // ---------------------------------------------------------------------

    public function test_staff_without_permission_cannot_view_or_create_broadcasts(): void
    {
        $staff = $this->createStaffUser();

        $this->actingAs($staff)->get('/broadcasts')->assertForbidden();
        $this->actingAs($staff)->get('/broadcasts/create')->assertForbidden();
        $this->actingAs($staff)->post('/broadcasts', [
            'name' => 'X', 'channel' => 'email', 'audience_type' => 'customers', 'subject' => 'S', 'body' => 'B',
        ])->assertForbidden();

        $this->assertSame(0, Broadcast::query()->count());
    }

    public function test_staff_role_does_not_receive_broadcast_permissions_but_super_admin_does(): void
    {
        $staff = $this->createStaffUser();
        $owner = $this->createSuperAdmin();

        $this->assertFalse($staff->can(Permissions::BROADCASTS_MANAGE));
        $this->assertFalse($staff->can(Permissions::BROADCASTS_SEND));
        $this->assertTrue($owner->can(Permissions::BROADCASTS_MANAGE));
        $this->assertTrue($owner->can(Permissions::BROADCASTS_SEND));
        $this->assertContains(Permissions::BROADCASTS_SEND, Permissions::all());
    }

    public function test_creating_a_broadcast_saves_a_draft_and_sends_nothing(): void
    {
        $owner = $this->createSuperAdmin();
        $this->emailContact();

        $response = $this->actingAs($owner)->post('/broadcasts', [
            'name' => 'October offer',
            'channel' => 'email',
            'audience_type' => 'customers',
            'subject' => 'Our October offer',
            'body' => 'Hello',
        ]);

        $broadcast = Broadcast::query()->sole();
        $response->assertRedirect(route('broadcasts.show', $broadcast));
        $this->assertSame(BroadcastStatus::Draft, $broadcast->status);
        $this->assertSame(0, BroadcastRecipient::query()->count());
        $this->assertSame(0, Message::query()->count());
        Mail::assertNothingSent();
        $this->assertTrue(AuditEvent::query()->where('event', 'broadcast.created')->exists());

        $this->actingAs($owner)->get(route('broadcasts.show', $broadcast))->assertOk();
        $this->actingAs($owner)->get('/broadcasts')->assertOk();
    }

    public function test_email_broadcast_requires_subject_and_body(): void
    {
        $owner = $this->createSuperAdmin();

        $this->actingAs($owner)->post('/broadcasts', [
            'name' => 'No content',
            'channel' => 'email',
            'audience_type' => 'customers',
        ])->assertSessionHasErrors(['subject', 'body']);

        $this->assertSame(0, Broadcast::query()->count());
    }

    public function test_manage_permission_alone_cannot_start_sending(): void
    {
        $manager = $this->staffWith(Permissions::BROADCASTS_MANAGE);
        $this->emailContact();
        $broadcast = $this->draft($manager);

        $this->actingAs($manager)->get(route('broadcasts.show', $broadcast))->assertOk();
        $this->actingAs($manager)
            ->post(route('broadcasts.send', $broadcast), ['confirm_recipient_count' => 1])
            ->assertForbidden();

        $this->expectException(AuthorizationException::class);
        try {
            app(BroadcastService::class)->start($broadcast, $manager, 1);
        } finally {
            $this->assertSame(BroadcastStatus::Draft, $broadcast->fresh()->status);
            $this->assertSame(0, Message::query()->count());
        }
    }

    public function test_explicitly_authorized_user_can_send(): void
    {
        $sender = $this->staffWith(Permissions::BROADCASTS_MANAGE, Permissions::BROADCASTS_SEND);
        $this->emailContact();
        $broadcast = $this->draft($sender);

        $this->actingAs($sender)
            ->post(route('broadcasts.send', $broadcast), ['confirm_recipient_count' => 1])
            ->assertRedirect(route('broadcasts.show', $broadcast));

        $this->assertSame(BroadcastStatus::Completed, $broadcast->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // Audiences
    // ---------------------------------------------------------------------

    public function test_customers_audience_includes_only_eligible_active_customers(): void
    {
        $owner = $this->createSuperAdmin();
        $customer = $this->emailContact();
        $this->emailContact([], 'prospect');
        $this->emailContact([], 'unknown');
        $this->emailContact(['archived_at' => now()]);

        $broadcast = $this->draft($owner, ['audience_type' => 'customers']);
        $preview = app(BroadcastService::class)->preview($broadcast);

        $this->assertSame([$customer->id], $this->eligibleIds($broadcast));
        $this->assertSame(1, $preview['exclusions'][BroadcastIneligibilityReason::Archived->value]);
    }

    public function test_customers_and_prospects_audience_excludes_unknown_and_archived(): void
    {
        $owner = $this->createSuperAdmin();
        $customer = $this->emailContact();
        $prospect = $this->emailContact([], 'prospect');
        $this->emailContact([], 'unknown');
        $this->emailContact(['archived_at' => now()], 'prospect');

        $broadcast = $this->draft($owner, ['audience_type' => 'customers_prospects']);

        $this->assertEqualsCanonicalizing([$customer->id, $prospect->id], $this->eligibleIds($broadcast));
    }

    public function test_selected_audience_only_includes_selected_eligible_contacts(): void
    {
        $owner = $this->createSuperAdmin();
        $chosen = $this->emailContact();
        $notChosen = $this->emailContact();
        $unknown = $this->emailContact([], 'unknown');
        $archived = $this->emailContact(['archived_at' => now()]);

        $broadcast = $this->draft($owner, [
            'audience_type' => 'selected',
            'selected_contact_ids' => [$chosen->id, $unknown->id, $archived->id],
        ]);
        $preview = app(BroadcastService::class)->preview($broadcast);

        $this->assertSame([$chosen->id], $this->eligibleIds($broadcast));
        $this->assertNotContains($notChosen->id, $this->eligibleIds($broadcast));
        $this->assertSame(2, $preview['excluded_count']);
        $this->assertSame(1, $preview['exclusions'][BroadcastIneligibilityReason::StatusNotInAudience->value]);
        $this->assertSame(1, $preview['exclusions'][BroadcastIneligibilityReason::Archived->value]);
    }

    // ---------------------------------------------------------------------
    // Consent
    // ---------------------------------------------------------------------

    public function test_whatsapp_requires_broadcast_opt_in_and_transactional_opt_in_alone_is_not_enough(): void
    {
        $this->enableBroadcastTemplate();
        $owner = $this->createSuperAdmin();
        $consented = $this->whatsappContact();
        $transactionalOnly = $this->whatsappContact([
            'whatsapp_broadcast_opt_in_at' => null,
            'whatsapp_broadcast_opt_in_source' => null,
        ]);

        $broadcast = $this->draft($owner, ['channel' => 'whatsapp']);
        $preview = app(BroadcastService::class)->preview($broadcast);

        $this->assertSame([$consented->id], $this->eligibleIds($broadcast));
        $this->assertSame(1, $preview['exclusions'][BroadcastIneligibilityReason::NoWhatsAppBroadcastOptIn->value]);
        $this->assertNotContains($transactionalOnly->id, $this->eligibleIds($broadcast));
    }

    public function test_whatsapp_broadcast_opt_out_excludes_contact(): void
    {
        $this->enableBroadcastTemplate();
        $owner = $this->createSuperAdmin();
        $this->whatsappContact([
            'whatsapp_broadcast_opt_out_at' => now(),
            'whatsapp_broadcast_opt_out_source' => ConsentSource::WhatsApp,
        ]);

        $broadcast = $this->draft($owner, ['channel' => 'whatsapp']);
        $preview = app(BroadcastService::class)->preview($broadcast);

        $this->assertSame(0, $preview['eligible_count']);
        $this->assertSame(1, $preview['exclusions'][BroadcastIneligibilityReason::WhatsAppBroadcastOptedOut->value]);
    }

    public function test_email_requires_opt_in_and_unsubscribe_excludes(): void
    {
        $owner = $this->createSuperAdmin();
        $optedIn = $this->emailContact();
        $this->emailContact(['email_broadcast_opt_in_at' => null, 'email_broadcast_opt_in_source' => null]);
        $this->emailContact([
            'email_broadcast_unsubscribed_at' => now(),
            'email_broadcast_unsubscribe_source' => ConsentSource::UnsubscribeLink,
        ]);

        $broadcast = $this->draft($owner);
        $preview = app(BroadcastService::class)->preview($broadcast);

        $this->assertSame([$optedIn->id], $this->eligibleIds($broadcast));
        $this->assertSame(1, $preview['exclusions'][BroadcastIneligibilityReason::NoEmailBroadcastOptIn->value]);
        $this->assertSame(1, $preview['exclusions'][BroadcastIneligibilityReason::EmailBroadcastUnsubscribed->value]);
    }

    public function test_staff_can_record_whatsapp_broadcast_opt_in_but_not_as_unsubscribe_link(): void
    {
        $owner = $this->createSuperAdmin();
        $contact = $this->whatsappContact(['whatsapp_broadcast_opt_in_at' => null, 'whatsapp_broadcast_opt_in_source' => null]);

        $payload = [
            'type' => $contact->type->value,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'organization_name' => $contact->organization_name,
            'whatsapp_id' => $contact->whatsapp_id,
            'phone' => $contact->phone,
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_source' => 'in_person',
            'whatsapp_broadcast_opt_in' => true,
        ];

        $this->actingAs($owner)
            ->put(route('contacts.update', $contact), [...$payload, 'whatsapp_broadcast_opt_in_source' => 'unsubscribe_link'])
            ->assertSessionHasErrors('whatsapp_broadcast_opt_in_source');

        $this->actingAs($owner)
            ->put(route('contacts.update', $contact), [...$payload, 'whatsapp_broadcast_opt_in_source' => 'whatsapp'])
            ->assertSessionHasNoErrors();

        $contact->refresh();
        $this->assertNotNull($contact->whatsapp_broadcast_opt_in_at);
        $this->assertSame(ConsentSource::WhatsApp, $contact->whatsapp_broadcast_opt_in_source);
    }

    // ---------------------------------------------------------------------
    // Gates: business switch, limit, confirmation
    // ---------------------------------------------------------------------

    public function test_broadcasts_enabled_defaults_to_false(): void
    {
        DB::table('businesses')->delete();

        $this->assertFalse((bool) Business::current()->fresh()->broadcasts_enabled);
    }

    public function test_sending_is_blocked_when_business_switch_is_off(): void
    {
        Business::current()->update(['broadcasts_enabled' => false]);
        $owner = $this->createSuperAdmin();
        $this->emailContact();
        $broadcast = $this->draft($owner);

        $this->actingAs($owner)
            ->post(route('broadcasts.send', $broadcast), ['confirm_recipient_count' => 1])
            ->assertSessionHasErrors('broadcast');

        $this->assertSame(BroadcastStatus::Draft, $broadcast->fresh()->status);
        $this->assertSame(0, BroadcastRecipient::query()->count());
        $this->assertSame(0, Message::query()->count());
        Mail::assertNothingSent();
    }

    public function test_business_settings_toggle_broadcasts_only_when_field_is_present(): void
    {
        $owner = $this->createSuperAdmin();
        Business::current()->update(['broadcasts_enabled' => false]);

        $base = [
            'name' => 'ADMAN Test',
            'outbound_email_enabled' => true,
            'outbound_whatsapp_enabled' => true,
            'tax_enabled' => false,
            'currency_code' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ];

        $this->actingAs($owner)->put('/settings/business', [...$base, 'broadcasts_enabled' => true])->assertSessionHasNoErrors();
        $this->assertTrue((bool) Business::current()->fresh()->broadcasts_enabled);

        $this->actingAs($owner)->put('/settings/business', $base)->assertSessionHasNoErrors();
        $this->assertTrue((bool) Business::current()->fresh()->broadcasts_enabled);

        $this->actingAs($owner)->put('/settings/business', [...$base, 'broadcasts_enabled' => false])->assertSessionHasNoErrors();
        $this->assertFalse((bool) Business::current()->fresh()->broadcasts_enabled);
    }

    public function test_audience_over_the_limit_is_refused_with_no_partial_send(): void
    {
        config(['adman.broadcasts.recipient_limit' => 2]);
        $owner = $this->createSuperAdmin();
        $this->emailContact();
        $this->emailContact();
        $this->emailContact();
        $broadcast = $this->draft($owner);

        $preview = app(BroadcastService::class)->preview($broadcast);
        $this->assertTrue($preview['over_limit']);

        try {
            app(BroadcastService::class)->start($broadcast, $owner, 3);
            $this->fail('An over-limit audience must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('larger than the broadcast limit of 2', $e->errors()['broadcast'][0]);
        }

        $this->assertSame(BroadcastStatus::Draft, $broadcast->fresh()->status);
        $this->assertSame(0, BroadcastRecipient::query()->count());
        $this->assertSame(0, Message::query()->count());
        Mail::assertNothingSent();
    }

    public function test_recipient_limit_can_never_exceed_500(): void
    {
        config(['adman.broadcasts.recipient_limit' => 5000]);
        $this->assertSame(500, BroadcastService::recipientLimit());

        config(['adman.broadcasts.recipient_limit' => 0]);
        $this->assertSame(1, BroadcastService::recipientLimit());
    }

    public function test_selected_contacts_over_500_are_rejected_by_validation(): void
    {
        $owner = $this->createSuperAdmin();

        $this->actingAs($owner)->post('/broadcasts', [
            'name' => 'Too many',
            'channel' => 'email',
            'audience_type' => 'selected',
            'selected_contact_ids' => range(1, 501),
            'subject' => 'S',
            'body' => 'B',
        ])->assertSessionHasErrors('selected_contact_ids');
    }

    public function test_send_is_refused_when_audience_changed_since_review(): void
    {
        $owner = $this->createSuperAdmin();
        $this->emailContact();
        $broadcast = $this->draft($owner);
        $this->emailContact();

        $this->actingAs($owner)
            ->post(route('broadcasts.send', $broadcast), ['confirm_recipient_count' => 1])
            ->assertSessionHasErrors('broadcast');

        $this->assertSame(BroadcastStatus::Draft, $broadcast->fresh()->status);
        $this->assertSame(0, Message::query()->count());
    }

    public function test_draft_cannot_be_sent_twice(): void
    {
        $owner = $this->createSuperAdmin();
        $this->emailContact();
        $broadcast = $this->draft($owner);
        $this->startNow($broadcast, $owner);

        $this->expectException(ValidationException::class);
        try {
            app(BroadcastService::class)->start($broadcast->fresh(), $owner, 1);
        } finally {
            $this->assertSame(1, Message::query()->count());
        }
    }

    // ---------------------------------------------------------------------
    // WhatsApp delivery
    // ---------------------------------------------------------------------

    public function test_whatsapp_broadcast_is_refused_without_configured_marketing_template(): void
    {
        config(['adman.whatsapp.templates.invoice' => ['name' => 'invoice_sent', 'language' => 'en', 'enabled' => true]]);
        config(['adman.whatsapp.broadcast_template' => ['name' => null, 'language' => null, 'enabled' => false, 'parameters' => '']]);
        $owner = $this->createSuperAdmin();
        $this->whatsappContact();
        $broadcast = $this->draft($owner, ['channel' => 'whatsapp']);

        $preview = app(BroadcastService::class)->preview($broadcast);
        $this->assertNull($preview['template']);
        $this->assertStringContainsString('No approved WhatsApp Marketing template', implode(' ', $preview['blockers']));

        $this->actingAs($owner)
            ->post(route('broadcasts.send', $broadcast), ['confirm_recipient_count' => 1])
            ->assertSessionHasErrors('broadcast');

        $this->assertSame([], $this->adapter->calls);
        $this->assertSame(0, Message::query()->count());
    }

    public function test_utility_template_can_never_be_used_as_the_broadcast_template(): void
    {
        config(['adman.whatsapp.templates' => [
            'quote' => ['name' => 'quote_document', 'language' => 'en', 'enabled' => true],
            'invoice' => ['name' => 'invoice_sent', 'language' => 'en', 'enabled' => true],
            'invoice_reminder' => ['name' => 'invoice_reminder', 'language' => 'en', 'enabled' => true],
            'payment_acknowledgement' => ['name' => 'payment_acknowledgement', 'language' => 'en', 'enabled' => true],
        ]]);

        foreach (['quote_document', 'INVOICE_SENT', 'invoice_reminder', 'payment_acknowledgement'] as $utility) {
            $this->enableBroadcastTemplate($utility);

            $this->assertNull(WhatsAppBroadcastTemplate::configured(), $utility.' must be refused');
            $this->assertStringContainsString('Utility', (string) WhatsAppBroadcastTemplate::problem());
        }
    }

    public function test_unsupported_template_parameter_is_refused(): void
    {
        $this->enableBroadcastTemplate('adman_promo_test', 'contact_name,discount_code');

        $this->assertNull(WhatsAppBroadcastTemplate::configured());
        $this->assertStringContainsString('discount_code', (string) WhatsAppBroadcastTemplate::problem());
    }

    public function test_whatsapp_broadcast_sends_marketing_template_and_stores_provider_ids(): void
    {
        $this->enableBroadcastTemplate('adman_promo_test', 'contact_name');
        $owner = $this->createSuperAdmin();
        $first = $this->whatsappContact(['display_name' => 'Ada Obi']);
        $second = $this->whatsappContact(['display_name' => 'Bola Ade']);
        $broadcast = $this->draft($owner, ['channel' => 'whatsapp']);

        $this->actingAs($owner)
            ->post(route('broadcasts.send', $broadcast), ['confirm_recipient_count' => 2])
            ->assertRedirect(route('broadcasts.show', $broadcast));

        $broadcast->refresh();
        $this->assertSame(BroadcastStatus::Completed, $broadcast->status);
        $this->assertSame('adman_promo_test', $broadcast->whatsapp_template_name);
        $this->assertSame(2, $broadcast->recipient_count);

        $calls = $this->templateCalls();
        $this->assertCount(2, $calls);
        $this->assertSame(['template', 'template'], array_column($this->adapter->calls, 0));
        foreach ($calls as $payload) {
            $this->assertSame('adman_promo_test', $payload->templateName);
            $this->assertNull($payload->templateKey);
            $this->assertNull($payload->headerDocumentMediaId);
        }
        $this->assertSame(['Ada Obi'], $calls[0]->bodyParameters);
        $this->assertSame($first->whatsapp_id, $calls[0]->to);
        $this->assertSame($second->whatsapp_id, $calls[1]->to);

        $recipients = BroadcastRecipient::query()->orderBy('id')->get();
        $this->assertSame(['wamid.TPL1', 'wamid.TPL2'], $recipients->pluck('provider_message_id')->all());
        foreach ($recipients as $recipient) {
            $this->assertSame(BroadcastRecipientStatus::Sent, $recipient->status);
            $this->assertNotNull($recipient->message_id);
            $message = Message::query()->findOrFail($recipient->message_id);
            $this->assertSame(MessageStatus::Sent, $message->status);
            $this->assertSame(WhatsAppOutboundService::KIND_BROADCAST, $message->meta['delivery_kind']);
            $this->assertNull($message->template_key);
            $this->assertSame($recipient->provider_message_id, $message->external_message_id);
        }

        foreach (['broadcast.send_requested', 'broadcast.started', 'broadcast.recipient_queued', 'broadcast.recipient_sent', 'broadcast.completed'] as $event) {
            $this->assertTrue(AuditEvent::query()->where('event', $event)->exists(), $event.' missing');
        }
        $this->assertSame(1, AuditEvent::query()->where('event', 'broadcast.completed')->count());
    }

    public function test_delivery_status_update_marks_recipient_delivered(): void
    {
        $this->enableBroadcastTemplate();
        $owner = $this->createSuperAdmin();
        $this->whatsappContact();
        $broadcast = $this->startNow($this->draft($owner, ['channel' => 'whatsapp']), $owner);

        $recipient = BroadcastRecipient::query()->sole();
        $message = Message::query()->findOrFail($recipient->message_id);
        $message->status = MessageStatus::Delivered;
        $message->save();

        $recipient->refresh();
        $this->assertSame(BroadcastRecipientStatus::Delivered, $recipient->status);
        $this->assertNotNull($recipient->delivered_at);
        $this->assertSame(1, app(BroadcastService::class)->counts($broadcast->id)['sent']);
        $this->assertSame(1, app(BroadcastService::class)->counts($broadcast->id)['delivered']);
    }

    public function test_queue_retries_cannot_duplicate_a_send(): void
    {
        $this->enableBroadcastTemplate();
        $owner = $this->createSuperAdmin();
        $contact = $this->whatsappContact();
        $broadcast = $this->startNow($this->draft($owner, ['channel' => 'whatsapp']), $owner);
        $message = Message::query()->sole();

        (new ProcessBroadcastJob($broadcast->id))->handle(app(BroadcastService::class));
        (new SendOutboundWhatsAppJob($message->id))->handle(app(WhatsAppOutboundService::class));
        app(WhatsAppOutboundService::class)->deliverQueuedMessage($message->fresh());

        $this->assertCount(1, $this->templateCalls());
        $this->assertSame(1, Message::query()->count());
        $this->assertSame(1, BroadcastRecipient::query()->count());

        $this->expectException(QueryException::class);
        BroadcastRecipient::query()->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $contact->id,
            'channel' => 'whatsapp',
            'status' => BroadcastRecipientStatus::Pending,
        ]);
    }

    public function test_failed_broadcast_message_is_never_resent(): void
    {
        $this->enableBroadcastTemplate();
        $this->adapter->templateResults = [WhatsAppDeliveryResult::failed('WhatsApp provider rejected or failed the send. Please retry later.', true)];
        $owner = $this->createSuperAdmin();
        $this->whatsappContact();
        $this->startNow($this->draft($owner, ['channel' => 'whatsapp']), $owner);

        $message = Message::query()->sole();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertSame(BroadcastRecipientStatus::Failed, BroadcastRecipient::query()->sole()->status);

        app(WhatsAppOutboundService::class)->deliverQueuedMessage($message);
        $this->assertCount(1, $this->templateCalls());

        $this->expectException(ValidationException::class);
        app(WhatsAppOutboundService::class)->retry($message->fresh(), $owner);
    }

    public function test_one_recipient_failure_does_not_stop_the_broadcast(): void
    {
        $this->enableBroadcastTemplate();
        $this->adapter->templateResults = [
            WhatsAppDeliveryResult::failed((string) WhatsAppErrorMapper::reasonForCode('131026'), false),
        ];
        $owner = $this->createSuperAdmin();
        $this->whatsappContact();
        $this->whatsappContact();
        $broadcast = $this->startNow($this->draft($owner, ['channel' => 'whatsapp']), $owner);

        $counts = app(BroadcastService::class)->counts($broadcast->id);
        $this->assertSame(1, $counts['failed']);
        $this->assertSame(1, $counts['sent']);
        $this->assertSame(BroadcastStatus::Completed, $broadcast->fresh()->status);
        $this->assertTrue(AuditEvent::query()->where('event', 'broadcast.recipient_failed')->exists());
    }

    public function test_account_level_whatsapp_error_stops_the_broadcast(): void
    {
        $this->enableBroadcastTemplate();
        $this->adapter->templateResults = [
            WhatsAppDeliveryResult::failed((string) WhatsAppErrorMapper::reasonForCode('132001'), false),
        ];
        $owner = $this->createSuperAdmin();
        $this->whatsappContact();
        $this->whatsappContact();
        $this->whatsappContact();
        $broadcast = $this->startNow($this->draft($owner, ['channel' => 'whatsapp']), $owner);

        $broadcast->refresh();
        $this->assertSame(BroadcastStatus::Failed, $broadcast->status);
        $this->assertStringContainsString('account-level', (string) $broadcast->failure_reason);
        $this->assertCount(1, $this->templateCalls());

        $counts = app(BroadcastService::class)->counts($broadcast->id);
        $this->assertSame(1, $counts['failed']);
        $this->assertSame(2, $counts['cancelled']);
        $this->assertTrue(AuditEvent::query()->where('event', 'broadcast.failed')->exists());
    }

    public function test_opt_out_after_queueing_is_honoured_at_delivery(): void
    {
        Queue::fake([SendOutboundWhatsAppJob::class]);
        $this->enableBroadcastTemplate();
        $owner = $this->createSuperAdmin();
        $leaving = $this->whatsappContact();
        $staying = $this->whatsappContact();
        $broadcast = $this->startNow($this->draft($owner, ['channel' => 'whatsapp']), $owner);

        $this->assertSame(2, BroadcastRecipient::query()->where('status', BroadcastRecipientStatus::Queued->value)->count());

        $leaving->update([
            'whatsapp_broadcast_opt_out_at' => now(),
            'whatsapp_broadcast_opt_out_source' => ConsentSource::WhatsApp,
        ]);

        foreach (Message::query()->orderBy('id')->get() as $message) {
            app(WhatsAppOutboundService::class)->deliverQueuedMessage($message);
        }

        $calls = $this->templateCalls();
        $this->assertCount(1, $calls);
        $this->assertSame($staying->whatsapp_id, $calls[0]->to);

        $skipped = BroadcastRecipient::query()->where('contact_id', $leaving->id)->sole();
        $this->assertSame(BroadcastRecipientStatus::Skipped, $skipped->status);
        $this->assertStringContainsString('opted out', strtolower((string) $skipped->failure_reason));
        $this->assertSame(BroadcastStatus::Completed, $broadcast->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // Cancellation
    // ---------------------------------------------------------------------

    public function test_cancellation_stops_new_sends_and_keeps_sent_records(): void
    {
        Queue::fake([ProcessBroadcastJob::class]);
        config(['adman.broadcasts.batch_size' => 1]);
        $this->enableBroadcastTemplate();
        $owner = $this->createSuperAdmin();
        $this->whatsappContact();
        $this->whatsappContact();
        $this->whatsappContact();
        $broadcast = $this->startNow($this->draft($owner, ['channel' => 'whatsapp']), $owner);
        Queue::assertPushed(ProcessBroadcastJob::class);

        $this->assertTrue(app(BroadcastService::class)->processNextBatch($broadcast->id));
        $this->assertCount(1, $this->templateCalls());

        $this->actingAs($owner)
            ->post(route('broadcasts.cancel', $broadcast))
            ->assertRedirect(route('broadcasts.show', $broadcast));

        $this->assertFalse(app(BroadcastService::class)->processNextBatch($broadcast->id));
        $this->assertCount(1, $this->templateCalls());

        $broadcast->refresh();
        $this->assertSame(BroadcastStatus::Cancelled, $broadcast->status);
        $this->assertSame($owner->id, $broadcast->cancelled_by);
        $counts = app(BroadcastService::class)->counts($broadcast->id);
        $this->assertSame(1, $counts['sent']);
        $this->assertSame(2, $counts['cancelled']);
        $this->assertSame(1, Message::query()->count());
        $this->assertTrue(AuditEvent::query()->where('event', 'broadcast.cancelled')->exists());
    }

    public function test_cancellation_blocks_messages_already_queued_but_not_delivered(): void
    {
        Queue::fake([SendOutboundWhatsAppJob::class]);
        $this->enableBroadcastTemplate();
        $owner = $this->createSuperAdmin();
        $this->whatsappContact();
        $broadcast = $this->startNow($this->draft($owner, ['channel' => 'whatsapp']), $owner);

        app(BroadcastService::class)->cancel($broadcast, $owner);
        app(WhatsAppOutboundService::class)->deliverQueuedMessage(Message::query()->sole());

        $this->assertSame([], $this->adapter->calls);
        $this->assertSame(BroadcastRecipientStatus::Cancelled, BroadcastRecipient::query()->sole()->status);
        $this->assertSame(MessageStatus::Failed, Message::query()->sole()->status);
    }

    public function test_finished_broadcast_cannot_be_cancelled(): void
    {
        $owner = $this->createSuperAdmin();
        $this->emailContact();
        $broadcast = $this->startNow($this->draft($owner), $owner);
        $this->assertSame(BroadcastStatus::Completed, $broadcast->fresh()->status);

        $this->actingAs($owner)
            ->post(route('broadcasts.cancel', $broadcast))
            ->assertSessionHasErrors('broadcast');
    }

    // ---------------------------------------------------------------------
    // Email delivery and unsubscribe
    // ---------------------------------------------------------------------

    public function test_email_broadcast_goes_through_existing_pipeline_with_unsubscribe(): void
    {
        $owner = $this->createSuperAdmin();
        $contact = $this->emailContact(['display_name' => 'Chidi Okeke']);
        $broadcast = $this->startNow($this->draft($owner), $owner);

        Mail::assertSent(DocumentOutboundMail::class, function (DocumentOutboundMail $mail) use ($contact) {
            $headers = $mail->headers()->text;

            return $mail->hasTo($contact->email)
                && $mail->payload->templateKey === EmailTemplateKey::Broadcast
                && ! $mail->payload->hasAttachment()
                && $mail->attachments() === []
                && str_contains((string) $mail->payload->unsubscribeUrl, '/email/unsubscribe/'.$contact->id.'/')
                && str_starts_with($headers['List-Unsubscribe'] ?? '', '<http')
                && ($headers['List-Unsubscribe-Post'] ?? null) === 'List-Unsubscribe=One-Click';
        });
        Mail::assertSent(DocumentOutboundMail::class, 1);

        $message = Message::query()->sole();
        $this->assertSame(EmailTemplateKey::Broadcast->value, $message->template_key);
        $this->assertNull($message->document_id);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame('Our October offer', $message->subject);

        $recipient = BroadcastRecipient::query()->sole();
        $this->assertSame(BroadcastRecipientStatus::Sent, $recipient->status);
        $this->assertSame(BroadcastStatus::Completed, $broadcast->fresh()->status);

        $html = (new DocumentOutboundMail($this->renderablePayload($message)))->render();
        $this->assertStringContainsString('Unsubscribe from these emails', $html);
        $this->assertStringContainsString('We have a new offer.', $html);
    }

    private function renderablePayload(Message $message): EmailDeliveryPayload
    {
        $url = EmailOutboundService::broadcastUnsubscribeUrl($message->conversation->contact_id, (int) $message->meta['broadcast_id']);

        return new EmailDeliveryPayload(
            toAddress: (string) $message->meta['to'],
            toName: 'Chidi Okeke',
            subject: (string) $message->subject,
            templateKey: EmailTemplateKey::Broadcast,
            viewData: [
                'business_name' => 'ADMAN Test',
                'customer_name' => 'Chidi Okeke',
                'subject' => (string) $message->subject,
                'body' => (string) $message->body,
                'unsubscribe_url' => $url,
            ],
            fromAddress: 'hello@adman.test',
            fromName: 'ADMAN Test',
            replyTo: null,
            messageId: $message->id,
            unsubscribeUrl: $url,
        );
    }

    public function test_unsubscribe_link_records_unsubscribe_and_excludes_future_broadcasts(): void
    {
        $owner = $this->createSuperAdmin();
        $contact = $this->emailContact();
        $broadcast = $this->startNow($this->draft($owner), $owner);
        $url = EmailOutboundService::broadcastUnsubscribeUrl($contact->id, $broadcast->id);

        $this->get($url)->assertOk()->assertSee('Unsubscribe');
        $this->assertNull($contact->fresh()->email_broadcast_unsubscribed_at);

        $this->post('/email/unsubscribe/'.$contact->id.'/'.$broadcast->id)->assertForbidden();
        $this->assertNull($contact->fresh()->email_broadcast_unsubscribed_at);

        $this->post($url)->assertOk()->assertSee('You have been unsubscribed');

        $contact->refresh();
        $this->assertNotNull($contact->email_broadcast_unsubscribed_at);
        $this->assertSame(ConsentSource::UnsubscribeLink, $contact->email_broadcast_unsubscribe_source);
        $this->assertNotNull($contact->email_broadcast_opt_in_at);
        $this->assertTrue(AuditEvent::query()
            ->where('event', 'contact.consent_changed')
            ->where('auditable_id', $contact->id)
            ->exists());

        $next = $this->draft($owner, ['name' => 'November offer']);
        $this->assertSame(0, app(BroadcastService::class)->preview($next)['eligible_count']);
    }

    public function test_transactional_email_is_unchanged_and_ignores_broadcast_consent(): void
    {
        $staff = $this->createStaffUser();
        $customer = Contact::factory()->customer()->create([
            'email' => 'billing-only@example.com',
            'email_broadcast_unsubscribed_at' => now(),
            'email_broadcast_unsubscribe_source' => ConsentSource::UnsubscribeLink,
        ]);
        $invoice = app(InvoiceService::class)->create([
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], [['description' => 'Consulting', 'quantity' => '1', 'unit' => 'hrs', 'unit_price' => '100.00']], $staff);
        $invoice = app(InvoiceService::class)->issue($invoice);

        app(EmailOutboundService::class)->queueInvoiceEmail($invoice->fresh(['contact', 'documents']), $staff);

        Mail::assertSent(DocumentOutboundMail::class, function (DocumentOutboundMail $mail) {
            return $mail->payload->templateKey === EmailTemplateKey::Invoice
                && $mail->payload->hasAttachment()
                && $mail->payload->unsubscribeUrl === null
                && ! array_key_exists('List-Unsubscribe', $mail->headers()->text);
        });
        $this->assertSame(MessageStatus::Sent, Message::query()->sole()->status);
    }

    // ---------------------------------------------------------------------
    // Isolation and adapter
    // ---------------------------------------------------------------------

    public function test_broadcasts_do_not_touch_financial_or_reminder_records(): void
    {
        Invoice::factory()->create();
        Payment::factory()->create();
        PaymentClaim::factory()->create();
        Quote::factory()->create();
        ReminderOccurrence::factory()->create();

        $tables = ['invoices', 'payments', 'payment_claims', 'quotes', 'reminder_occurrences', 'reminder_rules'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toArray()])->all();

        $this->enableBroadcastTemplate();
        $owner = $this->createSuperAdmin();
        $this->whatsappContact();
        $this->emailContact();
        $this->startNow($this->draft($owner, ['channel' => 'whatsapp']), $owner);
        $this->startNow($this->draft($owner, ['name' => 'Email one']), $owner);

        $after = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toArray()])->all();
        $this->assertEquals($before, $after);
        $this->assertSame(2, Broadcast::query()->where('status', BroadcastStatus::Completed->value)->count());
    }

    public function test_cloud_api_template_without_variables_sends_no_body_component(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);
        $adapter = app(WhatsAppCloudApiAdapter::class);

        $adapter->sendTemplate(new WhatsAppDeliveryPayload(
            to: '2348010000001',
            templateName: 'adman_promo_test',
            languageCode: 'en',
            templateKey: null,
            bodyParameters: [],
            messageId: 1,
        ));
        $adapter->sendTemplate(new WhatsAppDeliveryPayload(
            to: '2348010000001',
            templateName: 'invoice_sent',
            languageCode: 'en',
            templateKey: WhatsAppTemplateKey::Invoice,
            bodyParameters: ['Ada', 'INV-1', 'https://example.test/d/x'],
            messageId: 2,
        ));

        $recorded = Http::recorded()->map(fn (array $pair) => $pair[0])->values();
        $this->assertCount(2, $recorded);

        /** @var Request $marketing */
        $marketing = $recorded[0];
        $this->assertSame([], $marketing->data()['template']['components']);

        /** @var Request $utility */
        $utility = $recorded[1];
        $this->assertSame('body', $utility->data()['template']['components'][0]['type']);
        $this->assertCount(3, $utility->data()['template']['components'][0]['parameters']);
    }

    public function test_account_level_reason_classification(): void
    {
        $this->assertTrue(WhatsAppErrorMapper::isAccountLevelReason(WhatsAppErrorMapper::reasonForCode('132001')));
        $this->assertTrue(WhatsAppErrorMapper::isAccountLevelReason(WhatsAppErrorMapper::reasonForCode('131031')));
        $this->assertTrue(WhatsAppErrorMapper::isAccountLevelReason(WhatsAppErrorMapper::reasonForCode('190')));
        $this->assertFalse(WhatsAppErrorMapper::isAccountLevelReason(WhatsAppErrorMapper::reasonForCode('131026')));
        $this->assertFalse(WhatsAppErrorMapper::isAccountLevelReason(WhatsAppErrorMapper::reasonForCode('131050')));
        $this->assertFalse(WhatsAppErrorMapper::isAccountLevelReason(null));
    }

    public function test_unsubscribe_url_is_signed(): void
    {
        $owner = $this->createSuperAdmin();
        $contact = $this->emailContact();
        $broadcast = $this->draft($owner);

        $url = EmailOutboundService::broadcastUnsubscribeUrl($contact->id, $broadcast->id);
        $this->assertTrue(URL::hasValidSignature(\Illuminate\Http\Request::create($url)));
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
    }

    // ---------------------------------------------------------------------
    // Campaign message ({{2}}, Task 046)
    // ---------------------------------------------------------------------

    private const CAMPAIGN_MESSAGE = 'We will be closed on Monday, 5 October 2026, and normal operations will resume on Tuesday.';

    private function enableMessageTemplate(): void
    {
        $this->enableBroadcastTemplate('adman_promo_v2', 'contact_name,broadcast_message');
    }

    /**
     * @return array<string, mixed>
     */
    private function whatsappForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Holiday notice',
            'channel' => 'whatsapp',
            'audience_type' => 'customers',
            'whatsapp_message' => self::CAMPAIGN_MESSAGE,
        ], $overrides);
    }

    public function test_whatsapp_broadcast_requires_campaign_message_server_side(): void
    {
        $this->enableMessageTemplate();
        $owner = $this->createSuperAdmin();

        $this->actingAs($owner)->post('/broadcasts', $this->whatsappForm(['whatsapp_message' => null]))
            ->assertSessionHasErrors(['whatsapp_message' => 'A WhatsApp broadcast needs a campaign message.']);
        $this->actingAs($owner)->post('/broadcasts', $this->whatsappForm(['whatsapp_message' => "   \n  "]))
            ->assertSessionHasErrors('whatsapp_message');
        $this->actingAs($owner)->post('/broadcasts', $this->whatsappForm([
            'whatsapp_message' => str_repeat('a', WhatsAppBroadcastTemplate::MESSAGE_MAX_LENGTH + 1),
        ]))->assertSessionHasErrors('whatsapp_message');

        $this->assertSame(0, Broadcast::query()->count());

        // A draft saved without a message (e.g. under the old template) cannot be started.
        $this->whatsappContact();
        $broadcast = $this->draft($owner, ['channel' => 'whatsapp']);
        $preview = app(BroadcastService::class)->preview($broadcast);
        $this->assertContains('The WhatsApp template needs a campaign message. Edit the draft and add the message.', $preview['blockers']);

        $this->actingAs($owner)
            ->post(route('broadcasts.send', $broadcast), ['confirm_recipient_count' => 1])
            ->assertSessionHasErrors('broadcast');
        $this->assertSame(BroadcastStatus::Draft, $broadcast->fresh()->status);
        $this->assertSame([], $this->adapter->calls);
    }

    public function test_campaign_message_is_persisted_on_the_broadcast(): void
    {
        $this->enableMessageTemplate();
        $owner = $this->createSuperAdmin();

        $this->actingAs($owner)->post('/broadcasts', $this->whatsappForm())->assertSessionHasNoErrors();

        $broadcast = Broadcast::query()->sole();
        $this->assertSame(self::CAMPAIGN_MESSAGE, $broadcast->whatsapp_message);
        $this->assertNull($broadcast->subject);
        $this->assertNull($broadcast->body);
        $this->assertSame(0, Message::query()->count());

        $this->actingAs($owner)->get(route('broadcasts.edit', $broadcast))
            ->assertInertia(fn (Assert $page) => $page
                ->where('broadcast.whatsapp_message', self::CAMPAIGN_MESSAGE)
                ->where('whatsappTemplate.uses_message', true)
                ->where('whatsappTemplate.message_max_length', WhatsAppBroadcastTemplate::MESSAGE_MAX_LENGTH));
    }

    public function test_draft_campaign_message_can_be_edited(): void
    {
        $this->enableMessageTemplate();
        $owner = $this->createSuperAdmin();
        $this->actingAs($owner)->post('/broadcasts', $this->whatsappForm());
        $broadcast = Broadcast::query()->sole();

        $this->actingAs($owner)
            ->put(route('broadcasts.update', $broadcast), $this->whatsappForm(['whatsapp_message' => 'Our office hours have changed.']))
            ->assertRedirect(route('broadcasts.show', $broadcast));

        $this->assertSame('Our office hours have changed.', $broadcast->fresh()->whatsapp_message);
        $this->assertTrue(AuditEvent::query()->where('event', 'broadcast.updated')->exists());
    }

    public function test_campaign_message_cannot_be_changed_once_sending_starts(): void
    {
        Queue::fake([ProcessBroadcastJob::class]);
        $this->enableMessageTemplate();
        $owner = $this->createSuperAdmin();
        $this->whatsappContact();
        $broadcast = $this->startNow($this->draft($owner, ['channel' => 'whatsapp', 'whatsapp_message' => self::CAMPAIGN_MESSAGE]), $owner);
        $this->assertSame(BroadcastStatus::Queued, $broadcast->fresh()->status);

        $this->actingAs($owner)->get(route('broadcasts.edit', $broadcast))->assertRedirect(route('broadcasts.show', $broadcast));
        $this->actingAs($owner)
            ->put(route('broadcasts.update', $broadcast), $this->whatsappForm(['whatsapp_message' => 'Changed after queueing']))
            ->assertSessionHasErrors('broadcast');

        try {
            app(BroadcastService::class)->update($broadcast, $this->whatsappForm(['whatsapp_message' => 'Changed again']), $owner);
            $this->fail('A queued broadcast must not be editable.');
        } catch (ValidationException) {
        }

        $this->assertSame(self::CAMPAIGN_MESSAGE, $broadcast->fresh()->whatsapp_message);
    }

    public function test_template_parameters_are_contact_name_then_the_same_campaign_message_for_every_recipient(): void
    {
        $this->enableMessageTemplate();
        $owner = $this->createSuperAdmin();
        $this->whatsappContact(['display_name' => 'Ada Obi']);
        $this->whatsappContact(['display_name' => 'Bola Ade']);
        $this->whatsappContact(['display_name' => 'Chidi Eze']);
        $this->actingAs($owner)->post('/broadcasts', $this->whatsappForm());
        $broadcast = Broadcast::query()->sole();

        $this->actingAs($owner)
            ->post(route('broadcasts.send', $broadcast), ['confirm_recipient_count' => 3])
            ->assertRedirect(route('broadcasts.show', $broadcast));

        $calls = $this->templateCalls();
        $this->assertCount(3, $calls);
        $this->assertSame(['Ada Obi', self::CAMPAIGN_MESSAGE], $calls[0]->bodyParameters);
        $this->assertSame(['Bola Ade', self::CAMPAIGN_MESSAGE], $calls[1]->bodyParameters);
        $this->assertSame(['Chidi Eze', self::CAMPAIGN_MESSAGE], $calls[2]->bodyParameters);
        foreach ($calls as $payload) {
            $this->assertSame('adman_promo_v2', $payload->templateName);
            $this->assertNull($payload->templateKey);
        }

        $message = Message::query()->orderBy('id')->firstOrFail();
        $this->assertSame(['Ada Obi', self::CAMPAIGN_MESSAGE], $message->meta['body_parameters']);
        $this->assertStringContainsString('Campaign message: '.self::CAMPAIGN_MESSAGE, $message->body);
        $this->assertSame(BroadcastStatus::Completed, $broadcast->fresh()->status);
    }

    public function test_frontend_cannot_inject_template_parameters(): void
    {
        $this->enableMessageTemplate();
        $owner = $this->createSuperAdmin();
        $this->whatsappContact(['display_name' => 'Ada Obi']);

        foreach (['Hi {{1}}, offer inside', 'Use code {{2}}', 'Closing }} brace'] as $placeholder) {
            $this->actingAs($owner)->post('/broadcasts', $this->whatsappForm(['whatsapp_message' => $placeholder]))
                ->assertSessionHasErrors('whatsapp_message');
        }
        $this->assertSame(0, Broadcast::query()->count());

        $this->actingAs($owner)->post('/broadcasts', $this->whatsappForm([
            'body_parameters' => ['Injected name', 'Injected message', 'Extra'],
            'parameters' => 'contact_name,broadcast_message,discount_code',
            'whatsapp_template_name' => 'invoice_sent',
            'status' => 'queued',
        ]))->assertSessionHasNoErrors();
        $broadcast = Broadcast::query()->sole();
        $this->assertSame(BroadcastStatus::Draft, $broadcast->status);
        $this->assertNull($broadcast->whatsapp_template_name);

        $this->actingAs($owner)->post(route('broadcasts.send', $broadcast), [
            'confirm_recipient_count' => 1,
            'whatsapp_message' => 'Swapped at send time',
            'body_parameters' => ['Injected name', 'Injected message'],
        ])->assertRedirect(route('broadcasts.show', $broadcast));

        $calls = $this->templateCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('adman_promo_v2', $calls[0]->templateName);
        $this->assertSame(['Ada Obi', self::CAMPAIGN_MESSAGE], $calls[0]->bodyParameters);
        $this->assertSame(self::CAMPAIGN_MESSAGE, $broadcast->fresh()->whatsapp_message);
    }

    public function test_campaign_message_with_punctuation_and_line_breaks(): void
    {
        $this->enableMessageTemplate();
        $owner = $this->createSuperAdmin();
        $this->whatsappContact(['display_name' => 'Ada Obi']);
        $typed = "Dear valued customer — good news!\r\n\r\nOur new price list (₦25,000/month) starts 1/11/2026; questions? Reply \"HELP\" & we’ll call.\n\tThanks.";

        $this->actingAs($owner)->post('/broadcasts', $this->whatsappForm(['whatsapp_message' => $typed]))->assertSessionHasNoErrors();
        $broadcast = Broadcast::query()->sole();
        $this->assertSame(
            "Dear valued customer — good news!\n\nOur new price list (₦25,000/month) starts 1/11/2026; questions? Reply \"HELP\" & we’ll call.\n\tThanks.",
            $broadcast->whatsapp_message,
        );

        $this->startNow($broadcast, $owner);

        $sent = 'Dear valued customer — good news! Our new price list (₦25,000/month) starts 1/11/2026; questions? Reply "HELP" & we’ll call. Thanks.';
        $this->assertSame(['Ada Obi', $sent], $this->templateCalls()[0]->bodyParameters);
        $this->assertSame(['Ada Obi', $sent], Message::query()->sole()->meta['body_parameters']);
    }

    public function test_preview_renders_contact_name_and_campaign_message(): void
    {
        $this->enableMessageTemplate();
        config(['adman.whatsapp.business_account_id' => 'waba-test']);
        Http::fake(['graph.facebook.com/*/waba-test/message_templates*' => Http::response(['data' => [[
            'name' => 'adman_promo_v2',
            'language' => 'en',
            'status' => 'APPROVED',
            'components' => [[
                'type' => 'BODY',
                'text' => "Hello {{1}},\n\nWe’re sharing an update from Raslordeck Limited.\n\n{{2}}\n\nThank you for staying connected with us.",
            ]],
        ]]])]);
        $owner = $this->createSuperAdmin();
        $this->whatsappContact(['display_name' => 'Ada Obi']);
        $this->whatsappContact(['display_name' => 'Bola Ade']);
        $broadcast = $this->draft($owner, ['channel' => 'whatsapp', 'whatsapp_message' => self::CAMPAIGN_MESSAGE]);

        $this->actingAs($owner)->get(route('broadcasts.show', $broadcast))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.eligible_count', 2)
                ->where('preview.whatsapp.sample_contact_name', 'Ada Obi')
                ->where('preview.whatsapp.rendered', "Hello Ada Obi,\n\nWe’re sharing an update from Raslordeck Limited.\n\n"
                    .self::CAMPAIGN_MESSAGE."\n\nThank you for staying connected with us.")
                ->where('preview.whatsapp.parameters.0.label', 'Contact name (personalised for each recipient)')
                ->where('preview.whatsapp.parameters.1.label', 'Campaign message (the same for every recipient)')
                ->where('preview.whatsapp.parameters.1.value', self::CAMPAIGN_MESSAGE)
                ->where('broadcast.whatsapp_message', self::CAMPAIGN_MESSAGE));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'name=adman_promo_v2'));
        $this->assertSame([], $this->adapter->calls);
        $this->assertSame(0, Message::query()->count());
    }

    public function test_preview_falls_back_to_values_when_template_wording_is_unavailable(): void
    {
        $this->enableMessageTemplate();
        config(['adman.whatsapp.business_account_id' => 'waba-test']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190]], 401)]);
        $owner = $this->createSuperAdmin();
        $this->whatsappContact(['display_name' => 'Ada Obi']);
        $broadcast = $this->draft($owner, ['channel' => 'whatsapp', 'whatsapp_message' => "Line one\nLine two"]);

        $this->actingAs($owner)->get(route('broadcasts.show', $broadcast))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.whatsapp.rendered', null)
                ->where('preview.whatsapp.parameters.0.value', 'Ada Obi')
                ->where('preview.whatsapp.parameters.1.value', 'Line one Line two')
                ->where('preview.whatsapp.message_line_breaks_collapsed', true)
                ->where('preview.blockers', []));
    }

    public function test_campaign_message_broadcast_keeps_consent_rules(): void
    {
        $this->enableMessageTemplate();
        $owner = $this->createSuperAdmin();
        $consented = $this->whatsappContact();
        $this->whatsappContact(['whatsapp_broadcast_opt_in_at' => null, 'whatsapp_broadcast_opt_in_source' => null]);
        $this->whatsappContact(['whatsapp_broadcast_opt_out_at' => now(), 'whatsapp_broadcast_opt_out_source' => ConsentSource::WhatsApp]);
        $this->whatsappContact(['archived_at' => now()]);
        $broadcast = $this->draft($owner, ['channel' => 'whatsapp', 'whatsapp_message' => self::CAMPAIGN_MESSAGE]);

        $this->assertSame([$consented->id], $this->eligibleIds($broadcast));

        $this->startNow($broadcast, $owner);
        $this->assertSame([$consented->whatsapp_id], array_map(fn ($p) => $p->to, $this->templateCalls()));
        $this->assertSame([$consented->id], BroadcastRecipient::query()->pluck('contact_id')->all());
    }

    public function test_campaign_message_broadcast_keeps_recipient_limit(): void
    {
        config(['adman.broadcasts.recipient_limit' => 2]);
        $this->enableMessageTemplate();
        $owner = $this->createSuperAdmin();
        $this->whatsappContact();
        $this->whatsappContact();
        $this->whatsappContact();
        $broadcast = $this->draft($owner, ['channel' => 'whatsapp', 'whatsapp_message' => self::CAMPAIGN_MESSAGE]);

        $this->assertTrue(app(BroadcastService::class)->preview($broadcast)['over_limit']);
        $this->actingAs($owner)
            ->post(route('broadcasts.send', $broadcast), ['confirm_recipient_count' => 3])
            ->assertSessionHasErrors('broadcast');

        $this->assertSame([], $this->adapter->calls);
        $this->assertSame(0, BroadcastRecipient::query()->count());
    }

    public function test_campaign_message_broadcast_keeps_send_permission(): void
    {
        $this->enableMessageTemplate();
        $manager = $this->staffWith(Permissions::BROADCASTS_MANAGE);
        $this->whatsappContact();

        $this->actingAs($manager)->post('/broadcasts', $this->whatsappForm())->assertSessionHasNoErrors();
        $broadcast = Broadcast::query()->sole();

        $this->actingAs($manager)
            ->post(route('broadcasts.send', $broadcast), ['confirm_recipient_count' => 1])
            ->assertForbidden();
        $this->actingAs($this->createStaffUser())->post('/broadcasts', $this->whatsappForm())->assertForbidden();

        $this->assertSame(BroadcastStatus::Draft, $broadcast->fresh()->status);
        $this->assertSame([], $this->adapter->calls);
    }

    public function test_campaign_message_broadcast_blocked_when_business_switch_is_off(): void
    {
        $this->enableMessageTemplate();
        Business::current()->update(['broadcasts_enabled' => false]);
        $owner = $this->createSuperAdmin();
        $this->whatsappContact();
        $broadcast = $this->draft($owner, ['channel' => 'whatsapp', 'whatsapp_message' => self::CAMPAIGN_MESSAGE]);

        $this->assertContains('Broadcasts are turned off in Business settings.', app(BroadcastService::class)->preview($broadcast)['blockers']);
        $this->actingAs($owner)
            ->post(route('broadcasts.send', $broadcast), ['confirm_recipient_count' => 1])
            ->assertSessionHasErrors('broadcast');

        $this->assertSame(BroadcastStatus::Draft, $broadcast->fresh()->status);
        $this->assertSame([], $this->adapter->calls);
    }

    public function test_fixed_wording_template_refuses_a_campaign_message(): void
    {
        $this->enableBroadcastTemplate('adman_promo_test', 'contact_name');
        $owner = $this->createSuperAdmin();
        $this->whatsappContact(['display_name' => 'Ada Obi']);

        $this->actingAs($owner)->post('/broadcasts', $this->whatsappForm())
            ->assertSessionHasErrors('whatsapp_message');
        $this->actingAs($owner)->post('/broadcasts', $this->whatsappForm(['whatsapp_message' => null]))
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)->get('/broadcasts/create')
            ->assertInertia(fn (Assert $page) => $page->where('whatsappTemplate.uses_message', false));

        // A draft written for {{2}} cannot go out through a template that would silently drop it.
        $withMessage = $this->draft($owner, ['channel' => 'whatsapp', 'whatsapp_message' => self::CAMPAIGN_MESSAGE]);
        $this->assertStringContainsString('fixed wording', implode(' ', app(BroadcastService::class)->preview($withMessage)['blockers']));

        $this->startNow(Broadcast::query()->where('name', 'Holiday notice')->sole(), $owner);
        $this->assertSame(['Ada Obi'], $this->templateCalls()[0]->bodyParameters);
    }

    public function test_template_change_mid_send_to_require_a_message_stops_the_broadcast(): void
    {
        Queue::fake([ProcessBroadcastJob::class]);
        $this->enableBroadcastTemplate('adman_promo_v2', 'contact_name');
        $owner = $this->createSuperAdmin();
        $this->whatsappContact();
        $broadcast = $this->startNow($this->draft($owner, ['channel' => 'whatsapp']), $owner);

        $this->enableMessageTemplate();
        app(BroadcastService::class)->processNextBatch($broadcast->id);

        $broadcast->refresh();
        $this->assertSame(BroadcastStatus::Failed, $broadcast->status);
        $this->assertStringContainsString('campaign message', (string) $broadcast->failure_reason);
        $this->assertSame([], $this->adapter->calls);
        $this->assertSame(0, Message::query()->count());
    }

    public function test_transactional_templates_never_receive_the_campaign_message(): void
    {
        config(['adman.whatsapp.templates' => [
            'quote' => ['name' => 'quote_document', 'language' => 'en', 'enabled' => true],
            'invoice' => ['name' => 'invoice_sent', 'language' => 'en', 'enabled' => true],
            'invoice_reminder' => ['name' => 'invoice_reminder', 'language' => 'en', 'enabled' => true],
            'payment_acknowledgement' => ['name' => 'payment_acknowledgement', 'language' => 'en', 'enabled' => true],
        ]]);

        foreach (['quote_document', 'invoice_sent', 'invoice_reminder', 'payment_acknowledgement'] as $utility) {
            $this->enableBroadcastTemplate($utility, 'contact_name,broadcast_message');
            $this->assertNull(WhatsAppBroadcastTemplate::configured(), $utility.' must be refused');
        }

        $this->enableMessageTemplate();
        $owner = $this->createSuperAdmin();
        $this->whatsappContact();
        $this->startNow($this->draft($owner, ['channel' => 'whatsapp', 'whatsapp_message' => self::CAMPAIGN_MESSAGE]), $owner);

        foreach ($this->templateCalls() as $payload) {
            $this->assertSame('adman_promo_v2', $payload->templateName);
            $this->assertNull($payload->templateKey);
            $this->assertNull($payload->headerDocumentMediaId);
        }
    }

    public function test_email_broadcast_is_unaffected_by_campaign_message(): void
    {
        $this->enableMessageTemplate();
        $owner = $this->createSuperAdmin();
        $this->emailContact();

        $this->actingAs($owner)->post('/broadcasts', [
            'name' => 'Email offer',
            'channel' => 'email',
            'audience_type' => 'customers',
            'subject' => 'Our October offer',
            'body' => 'Hello',
            'whatsapp_message' => 'Ignored for email',
        ])->assertSessionHasNoErrors();

        $broadcast = Broadcast::query()->sole();
        $this->assertNull($broadcast->whatsapp_message);
        $this->assertSame([], app(BroadcastService::class)->preview($broadcast)['blockers']);

        $this->startNow($broadcast, $owner);
        $this->assertSame(BroadcastStatus::Completed, $broadcast->fresh()->status);
        $this->assertSame([], $this->adapter->calls);
        $this->assertSame(1, Message::query()->count());
    }
}
