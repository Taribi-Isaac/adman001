<?php

namespace Tests\Feature;

use App\Enums\CommunicationChannel;
use App\Enums\DiscountType;
use App\Enums\MessageStatus;
use App\Models\Business;
use App\Models\CommunicationIdentity;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ConversationService;
use App\Services\InvoiceService;
use App\Services\WhatsAppInboundService;
use App\Services\WhatsAppOutboundService;
use App\Support\WhatsAppPhone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\Concerns\OpensWhatsAppServiceWindow;
use Tests\TestCase;

class WhatsAppPhoneNormalizationTest extends TestCase
{
    use CreatesFoundationUsers;
    use OpensWhatsAppServiceWindow;
    use RefreshDatabase;

    private const CANONICAL = '2347054998090';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'adman.whatsapp.enabled' => true,
            'adman.whatsapp.access_token' => 'test-token',
            'adman.whatsapp.phone_number_id' => '123456789',
            'adman.whatsapp.app_secret' => 'test-app-secret',
        ]);

        Business::current()->update(['outbound_whatsapp_enabled' => true]);
    }

    public function test_nigerian_representations_normalize_to_the_canonical_identity(): void
    {
        foreach ([
            '07054998090',
            '+2347054998090',
            '2347054998090',
            '0705 499 8090',
            '0705-499-8090',
            '(0705) 499 8090',
            '+234 705 499 8090',
            '+234 (0) 705 499 8090',
            '+23407054998090',
            '002347054998090',
        ] as $input) {
            $this->assertSame(self::CANONICAL, WhatsAppPhone::normalize($input), $input);
        }

        $this->assertSame('2348012345678', WhatsAppPhone::normalize('08012345678'));
        $this->assertSame('2348112345678', WhatsAppPhone::normalize('08112345678'));
        $this->assertSame('2349067322344', WhatsAppPhone::normalize('09067322344'));
        $this->assertSame('2349112345678', WhatsAppPhone::normalize('09112345678'));
    }

    public function test_non_nigerian_international_numbers_are_not_converted(): void
    {
        $this->assertSame('447911123456', WhatsAppPhone::normalize('+44 7911 123456'));
        $this->assertSame('447911123456', WhatsAppPhone::normalize('00447911123456'));
        $this->assertSame('14155552671', WhatsAppPhone::normalize('+1 (415) 555-2671'));
        $this->assertSame('4915123456789', WhatsAppPhone::normalize('+49 151 23456789'));
        $this->assertSame('233241234567', WhatsAppPhone::normalize('+233 24 123 4567'));

        // Leading zeroes are only stripped for the Nigerian mobile pattern, never blindly.
        $this->assertSame('01234567890', WhatsAppPhone::normalize('01234567890'));
        $this->assertNull(WhatsAppPhone::normalize('0705'));
        $this->assertNull(WhatsAppPhone::normalize(''));
        $this->assertNull(WhatsAppPhone::normalize(null));
    }

    public function test_local_form_resolves_to_existing_canonical_identity_without_creating_another(): void
    {
        $contact = $this->taribi();
        $service = app(ConversationService::class);
        $existing = $service->findOrCreateIdentity(CommunicationChannel::WhatsApp, self::CANONICAL, 'Taribi Isaac', $contact);

        foreach (['07054998090', '+2347054998090', '2347054998090', '+234 705 499 8090'] as $input) {
            $resolved = $service->findOrCreateIdentity(CommunicationChannel::WhatsApp, $input, 'Taribi Isaac', $contact);
            $this->assertSame($existing->id, $resolved->id, $input);
        }

        $this->assertSame(1, CommunicationIdentity::query()->where('channel', 'whatsapp')->count());
        $this->assertSame(self::CANONICAL, $existing->fresh()->external_id);
    }

    public function test_alternate_representation_cannot_create_a_duplicate_identity(): void
    {
        $created = CommunicationIdentity::query()->create([
            'channel' => CommunicationChannel::WhatsApp,
            'external_id' => '+234 705 499 8090',
            'is_active' => true,
        ]);
        $this->assertSame(self::CANONICAL, $created->external_id);

        try {
            CommunicationIdentity::query()->create([
                'channel' => CommunicationChannel::WhatsApp,
                'external_id' => '07054998090',
                'is_active' => true,
            ]);
            $this->fail('A second identity for the same normalized number was created.');
        } catch (UniqueConstraintViolationException) {
            // Expected: the canonical value hits the unique (channel, external_id) index.
        }

        $this->assertSame(1, CommunicationIdentity::query()->where('channel', 'whatsapp')->count());
    }

    public function test_email_identities_are_not_touched_by_phone_normalization(): void
    {
        $identity = CommunicationIdentity::query()->create([
            'channel' => CommunicationChannel::Email,
            'external_id' => '07054998090@example.com',
            'is_active' => true,
        ]);

        $this->assertSame('07054998090@example.com', $identity->external_id);
    }

    public function test_staff_started_conversation_with_local_number_reuses_existing_identity(): void
    {
        $staff = $this->createStaffUser();
        $existing = app(ConversationService::class)->findOrCreateIdentity(CommunicationChannel::WhatsApp, self::CANONICAL);

        $this->actingAs($staff)->post(route('conversations.store'), [
            'channel' => CommunicationChannel::WhatsApp->value,
            'external_id' => '07054998090',
            'display_name' => 'Taribi Isaac',
            'initial_message' => 'Note from staff',
        ])->assertRedirect();

        $this->assertSame(1, CommunicationIdentity::query()->where('channel', 'whatsapp')->count());
        $this->assertSame($existing->id, Conversation::query()->firstOrFail()->communication_identity_id);
    }

    public function test_inbound_sender_resolves_to_existing_canonical_identity(): void
    {
        $contact = $this->taribi();
        $conversation = $this->recordWhatsAppInboundFrom($contact);
        $identityId = $conversation->communication_identity_id;

        app(WhatsAppInboundService::class)->handlePayload($this->inboundPayload('wamid.IN-CANONICAL', self::CANONICAL));
        app(WhatsAppInboundService::class)->handlePayload($this->inboundPayload('wamid.IN-PLUS', '+'.self::CANONICAL));

        $this->assertSame(1, CommunicationIdentity::query()->where('channel', 'whatsapp')->count());
        foreach (['wamid.IN-CANONICAL', 'wamid.IN-PLUS'] as $wamid) {
            $message = Message::query()->where('external_message_id', $wamid)->firstOrFail();
            $this->assertSame($identityId, $message->conversation->communication_identity_id);
        }
    }

    public function test_inbound_links_contact_stored_with_local_phone(): void
    {
        $contact = $this->taribi();

        app(WhatsAppInboundService::class)->handlePayload($this->inboundPayload('wamid.IN-FIRST', self::CANONICAL));

        $identity = CommunicationIdentity::query()->where('channel', 'whatsapp')->sole();
        $this->assertSame(self::CANONICAL, $identity->external_id);
        $this->assertSame($contact->id, $identity->contact_id);
    }

    public function test_outbound_to_contact_with_local_phone_sends_to_canonical_recipient(): void
    {
        $sentTo = [];
        Http::fake(function (Request $request) use (&$sentTo) {
            if (str_contains($request->url(), '/media')) {
                return Http::response(['id' => 'media.LOCAL'], 200);
            }
            $sentTo[] = $request->data()['to'] ?? null;

            return Http::response(['messages' => [['id' => 'wamid.LOCAL-OUT']]], 200);
        });

        $staff = $this->createStaffUser();
        $contact = $this->taribi();
        $this->recordWhatsAppInboundFrom($contact);

        $invoice = app(InvoiceService::class)->create([
            'contact_id' => $contact->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], [['description' => 'Consulting', 'quantity' => '1', 'unit' => 'hrs', 'unit_price' => '100.00']], $staff);
        $invoice = app(InvoiceService::class)->issue($invoice);

        $message = app(WhatsAppOutboundService::class)->queueInvoiceWhatsApp($invoice->fresh(['contact', 'documents']), $staff);

        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);
        $this->assertSame(self::CANONICAL, $message->fresh()->meta['to']);
        $this->assertSame([self::CANONICAL], $sentTo);
        $this->assertSame(1, CommunicationIdentity::query()->where('channel', 'whatsapp')->count());
        $this->assertSame(self::CANONICAL, $message->fresh()->conversation->identity->external_id);
        $this->assertSame('07054998090', $contact->fresh()->phone);
    }

    public function test_known_uat_identity_stays_resolvable_alongside_a_legacy_local_duplicate(): void
    {
        $contact = $this->taribi('+2347054998090');
        $canonical = app(ConversationService::class)->findOrCreateIdentity(CommunicationChannel::WhatsApp, self::CANONICAL, 'Taribi Isaac', $contact);

        // Production holds a legacy row created before normalization; it must be left as it is.
        DB::table('communication_identities')->insert([
            'channel' => CommunicationChannel::WhatsApp->value,
            'external_id' => '07054998090',
            'contact_id' => $contact->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(self::CANONICAL, WhatsAppPhone::fromContact($contact));
        foreach (['07054998090', '+2347054998090', self::CANONICAL] as $input) {
            $resolved = app(ConversationService::class)->findOrCreateIdentity(CommunicationChannel::WhatsApp, $input);
            $this->assertSame($canonical->id, $resolved->id, $input);
        }

        app(WhatsAppInboundService::class)->handlePayload($this->inboundPayload('wamid.IN-UAT', self::CANONICAL));
        $message = Message::query()->where('external_message_id', 'wamid.IN-UAT')->firstOrFail();
        $this->assertSame($canonical->id, $message->conversation->communication_identity_id);

        $this->assertSame(2, CommunicationIdentity::query()->where('channel', 'whatsapp')->count());
        $this->assertDatabaseHas('communication_identities', ['external_id' => '07054998090']);
    }

    private function taribi(string $phone = '07054998090'): Contact
    {
        return Contact::factory()->customer()->create([
            'display_name' => 'Taribi Isaac',
            'phone' => $phone,
            'whatsapp_id' => null,
            'whatsapp_opt_in' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundPayload(string $messageId, string $from): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'BUSINESS_ACCOUNT',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550001111', 'phone_number_id' => '123456789'],
                        'contacts' => [['profile' => ['name' => 'Taribi Isaac'], 'wa_id' => $from]],
                        'messages' => [[
                            'from' => $from,
                            'id' => $messageId,
                            'timestamp' => (string) time(),
                            'type' => 'text',
                            'text' => ['body' => 'Hello'],
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];
    }
}
