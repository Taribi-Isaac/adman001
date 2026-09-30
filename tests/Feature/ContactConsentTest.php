<?php

namespace Tests\Feature;

use App\Enums\BroadcastIneligibilityReason;
use App\Enums\CommunicationChannel;
use App\Enums\ConsentSource;
use App\Enums\ContactStatus;
use App\Enums\ContactType;
use App\Enums\DiscountType;
use App\Enums\MessageStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReminderChannelPreference;
use App\Enums\ReminderOccurrenceStatus;
use App\Mail\DocumentOutboundMail;
use App\Models\AuditEvent;
use App\Models\Business;
use App\Models\CommunicationIdentity;
use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use App\Services\BroadcastEligibilityService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\QuoteService;
use App\Services\ReminderService;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\TestCase;

class ContactConsentTest extends TestCase
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
            'adman.email.enabled' => true,
        ]);

        Business::current()->update([
            'outbound_whatsapp_enabled' => true,
            'outbound_email_enabled' => true,
            'email' => 'billing@adman.test',
        ]);
    }

    private function eligibility(): BroadcastEligibilityService
    {
        return app(BroadcastEligibilityService::class);
    }

    private function customer(array $attributes = []): Contact
    {
        return Contact::factory()->customer()->create(array_merge([
            'email' => 'consent@example.com',
            'whatsapp_id' => '2348012345678',
            'phone' => '+2348012345678',
        ], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function editPayload(Contact $contact, array $overrides = []): array
    {
        return array_merge([
            'type' => $contact->type->value,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'organization_name' => $contact->organization_name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'whatsapp_id' => $contact->whatsapp_id,
            'reminder_channel' => 'email',
        ], $overrides);
    }

    /**
     * @return list<array{description: string, quantity: string, unit_price: string}>
     */
    private function sampleItems(): array
    {
        return [['description' => 'Service', 'quantity' => '1', 'unit_price' => '100.00']];
    }

    private function limitedUser(array $permissions): User
    {
        $this->seedRolesAndPermissions();

        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::findOrCreate('Limited', 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $user;
    }

    public function test_new_contacts_are_not_broadcast_eligible_by_default(): void
    {
        $contact = $this->customer();

        $whatsapp = $this->eligibility()->forWhatsApp($contact);
        $email = $this->eligibility()->forEmail($contact);

        $this->assertFalse($whatsapp->eligible());
        $this->assertTrue($whatsapp->has(BroadcastIneligibilityReason::NoWhatsAppOptIn));
        $this->assertFalse($email->eligible());
        $this->assertTrue($email->has(BroadcastIneligibilityReason::NoEmailBroadcastOptIn));
    }

    public function test_staff_can_record_whatsapp_opt_in_with_source(): void
    {
        $staff = $this->createStaffUser();
        $contact = $this->customer();

        $this->actingAs($staff)
            ->put(route('contacts.update', $contact), $this->editPayload($contact, [
                'whatsapp_opt_in' => true,
                'whatsapp_opt_in_source' => ConsentSource::InPerson->value,
            ]))
            ->assertRedirect(route('contacts.show', $contact));

        $contact->refresh();
        $this->assertTrue($contact->whatsapp_opt_in);
        $this->assertNotNull($contact->whatsapp_opt_in_at);
        $this->assertSame(ConsentSource::InPerson, $contact->whatsapp_opt_in_source);
        $this->assertTrue($this->eligibility()->forWhatsApp($contact)->eligible());
    }

    public function test_opt_in_requires_a_source(): void
    {
        $staff = $this->createStaffUser();
        $contact = $this->customer();

        $this->actingAs($staff)
            ->from(route('contacts.edit', $contact))
            ->put(route('contacts.update', $contact), $this->editPayload($contact, [
                'email_broadcast_opt_in' => true,
            ]))
            ->assertSessionHasErrors('email_broadcast_opt_in_source');

        $this->assertNull($contact->fresh()->email_broadcast_opt_in_at);
        $this->assertSame(0, AuditEvent::query()->where('event', 'contact.consent_changed')->count());
    }

    public function test_invalid_source_is_rejected(): void
    {
        $staff = $this->createStaffUser();
        $contact = $this->customer();

        $this->actingAs($staff)
            ->from(route('contacts.edit', $contact))
            ->put(route('contacts.update', $contact), $this->editPayload($contact, [
                'email_broadcast_opt_in' => true,
                'email_broadcast_opt_in_source' => 'bought_list',
            ]))
            ->assertSessionHasErrors('email_broadcast_opt_in_source');
    }

    public function test_whatsapp_broadcast_opt_out_blocks_and_removal_restores(): void
    {
        $staff = $this->createStaffUser();
        $contact = $this->customer([
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_at' => now(),
            'whatsapp_opt_in_source' => ConsentSource::Website,
        ]);
        $this->assertTrue($this->eligibility()->forWhatsApp($contact)->eligible());

        $this->actingAs($staff)->put(route('contacts.update', $contact), $this->editPayload($contact, [
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_source' => ConsentSource::Website->value,
            'whatsapp_broadcast_opt_out' => true,
            'whatsapp_broadcast_opt_out_source' => ConsentSource::WhatsApp->value,
        ]))->assertRedirect();

        $contact->refresh();
        $this->assertTrue($contact->whatsapp_opt_in);
        $result = $this->eligibility()->forWhatsApp($contact);
        $this->assertFalse($result->eligible());
        $this->assertSame([BroadcastIneligibilityReason::WhatsAppBroadcastOptedOut], $result->reasons);

        $this->actingAs($staff)->put(route('contacts.update', $contact), $this->editPayload($contact, [
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_source' => ConsentSource::Website->value,
            'whatsapp_broadcast_opt_out' => false,
        ]))->assertRedirect();

        $contact->refresh();
        $this->assertNull($contact->whatsapp_broadcast_opt_out_at);
        $this->assertNull($contact->whatsapp_broadcast_opt_out_source);
        $this->assertTrue($this->eligibility()->forWhatsApp($contact)->eligible());
    }

    public function test_email_opt_in_unsubscribe_and_resubscribe(): void
    {
        $staff = $this->createStaffUser();
        $contact = $this->customer();

        $this->actingAs($staff)->put(route('contacts.update', $contact), $this->editPayload($contact, [
            'email_broadcast_opt_in' => true,
            'email_broadcast_opt_in_source' => ConsentSource::Website->value,
        ]))->assertRedirect();

        $contact->refresh();
        $this->assertTrue($this->eligibility()->forEmail($contact)->eligible());

        $this->actingAs($staff)->put(route('contacts.update', $contact), $this->editPayload($contact, [
            'email_broadcast_opt_in' => true,
            'email_broadcast_opt_in_source' => ConsentSource::Website->value,
            'email_broadcast_unsubscribed' => true,
            'email_broadcast_unsubscribe_source' => ConsentSource::Phone->value,
        ]))->assertRedirect();

        $contact->refresh();
        $this->assertNotNull($contact->email_broadcast_unsubscribed_at);
        $this->assertSame(ConsentSource::Phone, $contact->email_broadcast_unsubscribe_source);
        $result = $this->eligibility()->forEmail($contact);
        $this->assertFalse($result->eligible());
        $this->assertSame([BroadcastIneligibilityReason::EmailBroadcastUnsubscribed], $result->reasons);

        $this->actingAs($staff)->put(route('contacts.update', $contact), $this->editPayload($contact, [
            'email_broadcast_opt_in' => true,
            'email_broadcast_opt_in_source' => ConsentSource::Website->value,
            'email_broadcast_unsubscribed' => false,
        ]))->assertRedirect();

        $this->assertTrue($this->eligibility()->forEmail($contact->fresh())->eligible());
    }

    public function test_email_eligibility_rules(): void
    {
        $consented = [
            'email_broadcast_opt_in_at' => now(),
            'email_broadcast_opt_in_source' => ConsentSource::InPerson,
        ];

        $this->assertTrue($this->eligibility()->forEmail($this->customer($consented))->eligible());

        $archived = Contact::factory()->customer()->archived()->create(['email' => 'arch@example.com', ...$consented]);
        $this->assertTrue($this->eligibility()->forEmail($archived)->has(BroadcastIneligibilityReason::Archived));

        $prospect = Contact::factory()->prospect()->create(['email' => 'prospect@example.com', ...$consented]);
        $this->assertTrue($this->eligibility()->forEmail($prospect)->has(BroadcastIneligibilityReason::StatusNotInAudience));
        $this->assertTrue($this->eligibility()->forEmail($prospect, [ContactStatus::Prospect, ContactStatus::Customer])->eligible());

        $noEmail = Contact::factory()->customer()->create(['email' => null, 'phone' => '+2348000000001', ...$consented]);
        $this->assertTrue($this->eligibility()->forEmail($noEmail)->has(BroadcastIneligibilityReason::NoValidEmail));

        Business::current()->update(['outbound_email_enabled' => false]);
        $this->assertTrue($this->eligibility()->forEmail($this->customer(['email' => 'off@example.com', ...$consented]))
            ->has(BroadcastIneligibilityReason::ChannelDisabled));
    }

    public function test_whatsapp_eligibility_rules(): void
    {
        $consented = [
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_at' => now(),
            'whatsapp_opt_in_source' => ConsentSource::InPerson,
        ];

        $contact = $this->customer($consented);
        $this->assertTrue($this->eligibility()->check($contact, CommunicationChannel::WhatsApp)->eligible());

        $noNumber = Contact::factory()->customer()->create([
            'email' => 'nonumber@example.com', 'phone' => null, 'whatsapp_id' => null, ...$consented,
        ]);
        $this->assertTrue($this->eligibility()->forWhatsApp($noNumber)->has(BroadcastIneligibilityReason::NoWhatsAppNumber));

        $other = $this->customer(['email' => 'other@example.com', 'whatsapp_id' => '2348077777777', 'phone' => null]);
        $linked = $this->customer(['email' => 'linked@example.com', 'whatsapp_id' => '2348066666666', 'phone' => null, ...$consented]);
        CommunicationIdentity::query()->create([
            'channel' => CommunicationChannel::WhatsApp->value,
            'external_id' => '2348066666666',
            'contact_id' => $other->id,
            'is_active' => true,
        ]);
        $this->assertTrue($this->eligibility()->forWhatsApp($linked)->has(BroadcastIneligibilityReason::WhatsAppIdentityLinkedElsewhere));

        CommunicationIdentity::query()->create([
            'channel' => CommunicationChannel::WhatsApp->value,
            'external_id' => '2348012345678',
            'contact_id' => $contact->id,
            'is_active' => false,
        ]);
        $this->assertTrue($this->eligibility()->forWhatsApp($contact)->has(BroadcastIneligibilityReason::WhatsAppIdentityInactive));

        Business::current()->update(['outbound_whatsapp_enabled' => false]);
        $this->assertTrue($this->eligibility()->forWhatsApp($linked)->has(BroadcastIneligibilityReason::ChannelDisabled));

        $this->expectException(InvalidArgumentException::class);
        $this->eligibility()->forWhatsApp($contact, [ContactStatus::Unknown]);
    }

    public function test_legacy_whatsapp_opt_in_without_evidence_is_not_broadcast_eligible(): void
    {
        $legacy = $this->customer(['whatsapp_opt_in' => true]);

        $result = $this->eligibility()->forWhatsApp($legacy);
        $this->assertFalse($result->eligible());
        $this->assertSame([BroadcastIneligibilityReason::WhatsAppOptInNotRecorded], $result->reasons);

        $staff = $this->createStaffUser();

        // Saving the edit form without choosing a source leaves the legacy flag untouched.
        $this->actingAs($staff)->put(route('contacts.update', $legacy), $this->editPayload($legacy, [
            'whatsapp_opt_in' => true,
        ]))->assertRedirect();
        $legacy->refresh();
        $this->assertTrue($legacy->whatsapp_opt_in);
        $this->assertNull($legacy->whatsapp_opt_in_at);
        $this->assertSame(0, AuditEvent::query()->where('event', 'contact.consent_changed')->count());

        $this->actingAs($staff)->put(route('contacts.update', $legacy), $this->editPayload($legacy, [
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_source' => ConsentSource::StaffRecorded->value,
        ]))->assertRedirect();
        $legacy->refresh();
        $this->assertNotNull($legacy->whatsapp_opt_in_at);
        $this->assertTrue($this->eligibility()->forWhatsApp($legacy)->eligible());
    }

    public function test_consent_change_is_audited(): void
    {
        $staff = $this->createStaffUser();
        $contact = $this->customer();

        $this->actingAs($staff)->put(route('contacts.update', $contact), $this->editPayload($contact, [
            'email_broadcast_opt_in' => true,
            'email_broadcast_opt_in_source' => ConsentSource::Website->value,
        ]))->assertRedirect();

        $event = AuditEvent::query()->where('event', 'contact.consent_changed')->sole();
        $this->assertSame($contact->id, (int) $event->auditable_id);
        $this->assertSame($staff->id, (int) $event->actor_id);
        $this->assertSame('email_broadcast_opt_in', $event->new_values['consent']);
        $this->assertSame('email', $event->new_values['channel']);
        $this->assertTrue($event->new_values['state']);
        $this->assertSame('website', $event->new_values['source']);
        $this->assertNotNull($event->new_values['at']);
        $this->assertFalse($event->old_values['state']);
        $this->assertNull($event->old_values['source']);

        $this->actingAs($staff)->put(route('contacts.update', $contact), $this->editPayload($contact, [
            'email_broadcast_opt_in' => false,
        ]))->assertRedirect();

        $removal = AuditEvent::query()->where('event', 'contact.consent_changed')->latest('id')->first();
        $this->assertFalse($removal->new_values['state']);
        $this->assertTrue($removal->old_values['state']);
        $this->assertSame('website', $removal->old_values['source']);

        $updated = AuditEvent::query()->where('event', 'contact.updated')->latest('id')->first();
        $this->assertArrayNotHasKey('email_broadcast_opt_in_at', $updated->new_values ?? []);
    }

    public function test_consent_updates_require_contacts_update_permission(): void
    {
        $contact = $this->customer();
        $viewer = $this->limitedUser([Permissions::CONTACTS_VIEW]);

        $this->actingAs($viewer)
            ->put(route('contacts.update', $contact), $this->editPayload($contact, [
                'email_broadcast_opt_in' => true,
                'email_broadcast_opt_in_source' => ConsentSource::Website->value,
            ]))
            ->assertForbidden();

        $this->assertNull($contact->fresh()->email_broadcast_opt_in_at);
    }

    public function test_creating_with_consent_requires_contacts_update_permission(): void
    {
        $creator = $this->limitedUser([Permissions::CONTACTS_VIEW, Permissions::CONTACTS_CREATE]);

        $this->actingAs($creator)
            ->post(route('contacts.store'), [
                'type' => ContactType::Individual->value,
                'email' => 'new@example.com',
                'email_broadcast_opt_in' => true,
                'email_broadcast_opt_in_source' => ConsentSource::Website->value,
            ])
            ->assertForbidden();
        $this->assertSame(0, Contact::query()->count());

        $this->actingAs($creator)
            ->post(route('contacts.store'), [
                'type' => ContactType::Individual->value,
                'email' => 'new@example.com',
                'email_broadcast_opt_in' => false,
            ])
            ->assertRedirect();
        $this->assertNull(Contact::query()->sole()->email_broadcast_opt_in_at);
    }

    public function test_staff_can_create_contact_with_recorded_consent(): void
    {
        $staff = $this->createStaffUser();

        $this->actingAs($staff)->post(route('contacts.store'), [
            'type' => ContactType::Individual->value,
            'email' => 'signup@example.com',
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_source' => ConsentSource::InPerson->value,
            'email_broadcast_opt_in' => true,
            'email_broadcast_opt_in_source' => ConsentSource::InPerson->value,
        ])->assertRedirect();

        $contact = Contact::query()->sole();
        $this->assertTrue($contact->whatsapp_opt_in);
        $this->assertNotNull($contact->whatsapp_opt_in_at);
        $this->assertNotNull($contact->email_broadcast_opt_in_at);
        $this->assertSame(2, AuditEvent::query()->where('event', 'contact.consent_changed')->count());
    }

    public function test_email_unsubscribe_does_not_block_transactional_emails(): void
    {
        Mail::fake();
        $staff = $this->createStaffUser();
        $contact = $this->customer([
            'email_broadcast_unsubscribed_at' => now(),
            'email_broadcast_unsubscribe_source' => ConsentSource::Other,
        ]);

        $quote = app(QuoteService::class)->issue(app(QuoteService::class)->create([
            'contact_id' => $contact->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff));
        $invoice = app(InvoiceService::class)->issue(app(InvoiceService::class)->create([
            'contact_id' => $contact->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff));
        $payment = app(PaymentService::class)->record($invoice, [
            'amount' => '10.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
            'payment_date' => now()->toDateString(),
        ], $staff, true);

        $this->actingAs($staff)->post(route('quotes.send-email', $quote))->assertSessionHasNoErrors();
        $this->actingAs($staff)->post(route('invoices.send-email', $invoice))->assertSessionHasNoErrors();
        $this->actingAs($staff)->post(route('payments.send-email', $payment))->assertSessionHasNoErrors();

        Mail::assertSent(DocumentOutboundMail::class, 3);
        $this->assertSame(3, Message::query()->where('channel', 'email')->where('status', MessageStatus::Sent->value)->count());
    }

    public function test_email_unsubscribe_does_not_block_invoice_reminders(): void
    {
        Mail::fake();
        Business::current()->update(['invoice_reminders_enabled' => true]);
        app(ReminderService::class)->ensureDefaultRules();

        $staff = $this->createStaffUser();
        $contact = $this->customer([
            'reminder_channel' => ReminderChannelPreference::Email,
            'email_broadcast_unsubscribed_at' => now(),
            'email_broadcast_unsubscribe_source' => ConsentSource::Other,
        ]);
        $tz = Business::current()->timezone ?: 'UTC';
        $due = CarbonImmutable::now($tz)->addDays(7);
        $invoice = app(InvoiceService::class)->issue(app(InvoiceService::class)->create([
            'contact_id' => $contact->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
            'due_date' => $due->toDateString(),
        ], $this->sampleItems(), $staff));

        $occurrence = app(ReminderService::class)->processDue($due->subDays(7), dispatchJobs: false)
            ->firstWhere('invoice_id', $invoice->id);
        $this->assertNotNull($occurrence);
        app(ReminderService::class)->processOccurrence($occurrence);

        $this->assertSame(ReminderOccurrenceStatus::Queued, $occurrence->fresh()->status);
        Mail::assertSent(DocumentOutboundMail::class);
    }

    public function test_whatsapp_broadcast_opt_out_does_not_block_transactional_whatsapp(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/media')) {
                return Http::response(['id' => 'media.TEST'], 200);
            }

            return Http::response(['messages' => [['id' => 'wamid.CONSENT']]], 200);
        });
        config(['adman.whatsapp.templates.invoice' => 'adman_invoice']);

        $staff = $this->createStaffUser();
        $contact = $this->customer([
            'whatsapp_opt_in' => true,
            'whatsapp_broadcast_opt_out_at' => now(),
            'whatsapp_broadcast_opt_out_source' => ConsentSource::WhatsApp,
        ]);
        $invoice = app(InvoiceService::class)->issue(app(InvoiceService::class)->create([
            'contact_id' => $contact->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff));

        $this->actingAs($staff)
            ->post(route('invoices.send-whatsapp', $invoice))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $message = Message::query()->where('channel', 'whatsapp')->sole();
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertFalse($this->eligibility()->forWhatsApp($contact)->eligible());
    }

    public function test_whatsapp_broadcast_opt_out_does_not_block_whatsapp_reminders(): void
    {
        Queue::fake();
        Business::current()->update(['invoice_reminders_enabled' => true]);
        app(ReminderService::class)->ensureDefaultRules();

        $staff = $this->createStaffUser();
        $contact = $this->customer([
            'whatsapp_opt_in' => true,
            'reminder_channel' => ReminderChannelPreference::WhatsApp,
            'whatsapp_broadcast_opt_out_at' => now(),
            'whatsapp_broadcast_opt_out_source' => ConsentSource::WhatsApp,
        ]);
        $tz = Business::current()->timezone ?: 'UTC';
        $due = CarbonImmutable::now($tz)->addDays(7);
        $invoice = app(InvoiceService::class)->issue(app(InvoiceService::class)->create([
            'contact_id' => $contact->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
            'due_date' => $due->toDateString(),
        ], $this->sampleItems(), $staff));

        $occurrence = app(ReminderService::class)->processDue($due->subDays(7), dispatchJobs: false)
            ->firstWhere('invoice_id', $invoice->id);
        $this->assertNotNull($occurrence);
        app(ReminderService::class)->processOccurrence($occurrence);

        $this->assertNotSame(ReminderOccurrenceStatus::NotDeliverable, $occurrence->fresh()->status);
    }

    public function test_migration_adds_nullable_columns_and_does_not_grant_consent(): void
    {
        foreach ([
            'whatsapp_opt_in_at', 'whatsapp_opt_in_source',
            'whatsapp_broadcast_opt_out_at', 'whatsapp_broadcast_opt_out_source',
            'email_broadcast_opt_in_at', 'email_broadcast_opt_in_source',
            'email_broadcast_unsubscribed_at', 'email_broadcast_unsubscribe_source',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('contacts', $column), $column);
        }

        $legacyOptIn = $this->customer(['whatsapp_opt_in' => true]);
        $noOptIn = $this->customer(['email' => 'no@example.com', 'whatsapp_id' => '2348011111111', 'phone' => null]);

        foreach ([$legacyOptIn->fresh(), $noOptIn->fresh()] as $contact) {
            $this->assertNull($contact->whatsapp_opt_in_at);
            $this->assertNull($contact->email_broadcast_opt_in_at);
            $this->assertNull($contact->whatsapp_broadcast_opt_out_at);
            $this->assertNull($contact->email_broadcast_unsubscribed_at);
            $this->assertFalse($this->eligibility()->forWhatsApp($contact)->eligible());
            $this->assertFalse($this->eligibility()->forEmail($contact)->eligible());
        }

        $this->assertTrue($legacyOptIn->fresh()->whatsapp_opt_in);
        $this->assertFalse($noOptIn->fresh()->whatsapp_opt_in);
    }
}
