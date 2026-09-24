<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppMediaClient;
use App\Enums\ConversationMode;
use App\Enums\DiscountType;
use App\Mail\DocumentOutboundMail;
use App\Models\Business;
use App\Models\BusinessKnowledgeArticle;
use App\Models\BusinessOffering;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Notifications\InboundAttachmentReceived;
use App\Services\Ai\AiBusinessContextAssembler;
use App\Services\ConversationService;
use App\Services\EmailOutboundService;
use App\Services\InvoiceService;
use App\Services\WhatsAppInboundService;
use App\Support\DocumentSnapshots;
use App\Support\Permissions;
use App\Support\WhatsAppMediaDownloadResult;
use App\WhatsApp\Adapters\FakeWhatsAppMediaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\TestCase;

class DocumentAttachmentsAndKnowledgeTest extends TestCase
{
    use CreatesFoundationUsers;
    use RefreshDatabase;

    /**
     * @return list<array{description: string, quantity: string, unit_price: string}>
     */
    private function sampleItems(): array
    {
        return [[
            'description' => 'Consulting',
            'quantity' => '1',
            'unit_price' => '100.00',
        ]];
    }

    public function test_email_attaches_pdf_from_private_storage(): void
    {
        Mail::fake();
        Business::current()->update(['email' => 'billing@adman.test']);
        $staff = $this->createStaffUser();
        $customer = Contact::factory()->customer()->create(['email' => 'customer@example.com']);
        $invoice = app(InvoiceService::class)->create([
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff);
        $invoice = app(InvoiceService::class)->issue($invoice);

        $this->actingAs($staff)->post(route('invoices.send-email', $invoice))->assertRedirect();

        Mail::assertSent(DocumentOutboundMail::class, function (DocumentOutboundMail $mail) {
            $payload = $mail->payload;
            $this->assertTrue($payload->hasAttachment());
            $this->assertSame('application/pdf', $payload->attachmentMime);
            $this->assertNotNull($payload->attachmentFilename);
            $this->assertTrue(Storage::disk($payload->attachmentDisk)->exists($payload->attachmentPath));

            $attachments = $mail->attachments();
            $this->assertCount(1, $attachments);

            return true;
        });
    }

    public function test_email_fails_gracefully_when_pdf_missing(): void
    {
        Business::current()->update(['email' => 'billing@adman.test']);
        $staff = $this->createStaffUser();
        $customer = Contact::factory()->customer()->create(['email' => 'missing-pdf@example.com']);
        $invoice = app(InvoiceService::class)->create([
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff);
        $invoice = app(InvoiceService::class)->issue($invoice);
        $generated = app(\App\Services\DocumentService::class)->generateInvoicePdf($invoice, $staff, false);
        $document = $generated['document'];
        $this->assertNotNull($document);
        Storage::disk($document->disk)->delete($document->path);

        $message = app(EmailOutboundService::class)->queueInvoiceEmail($invoice->fresh(['contact', 'documents']), $staff);
        $this->assertSame(\App\Enums\MessageStatus::Failed, $message->fresh()->status);
        $this->assertStringContainsString('missing', strtolower((string) $message->fresh()->failure_reason));
    }

    public function test_communication_settings_page_is_truthful(): void
    {
        $staff = $this->createStaffUser();
        Business::current()->update([
            'outbound_email_enabled' => true,
            'outbound_whatsapp_enabled' => true,
        ]);

        $this->actingAs($staff)
            ->get(route('settings.communication.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/communication/Edit')
                ->where('channels.email.outbound_enabled', true)
                ->where('channels.whatsapp.document_delivery', 'pdf_attachment')
                ->has('message_types'));
    }

    public function test_customer_facing_pdfs_omit_internal_lifecycle_and_duplicate_name(): void
    {
        $staff = $this->createStaffUser();
        $business = Business::current();
        $business->update([
            'name' => 'Raslordeck Limited',
            'legal_name' => 'Raslordeck Limited',
        ]);

        $customer = Contact::factory()->customer()->create(['display_name' => 'Bill Customer']);
        $invoice = app(InvoiceService::class)->create([
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff);
        $invoice = app(InvoiceService::class)->issue($invoice);
        $invoice->refresh();
        $invoice->business_snapshot = DocumentSnapshots::business($business->fresh());
        $invoice->save();

        $html = view('documents.invoice', [
            'invoice' => $invoice->load('items'),
            'business' => $invoice->business_snapshot,
            'customer' => $invoice->customer_snapshot,
            'items' => $invoice->items,
            'logoSrc' => null,
        ])->render();

        $this->assertSame(1, substr_count($html, 'Raslordeck Limited'));
        $this->assertStringNotContainsString('Lifecycle', $html);
        $this->assertStringNotContainsString('Payment: Unpaid', $html);
        $this->assertStringNotContainsString('Due state', $html);
        $this->assertStringNotContainsString('Currency:', $html);
        $this->assertStringContainsString('INVOICE', $html);
        $this->assertStringContainsString('Balance due', $html);

        $quote = app(\App\Services\QuoteService::class)->create([
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff);
        $quote = app(\App\Services\QuoteService::class)->issue($quote);
        $quote->business_snapshot = DocumentSnapshots::business($business->fresh());
        $quote->save();

        $quoteHtml = view('documents.quote', [
            'quote' => $quote->load('items'),
            'business' => $quote->business_snapshot,
            'customer' => $quote->customer_snapshot,
            'items' => $quote->items,
            'logoSrc' => null,
        ])->render();

        $this->assertStringNotContainsString('Lifecycle', $quoteHtml);
        $this->assertStringNotContainsString('Status:', $quoteHtml);
        $this->assertStringContainsString('QUOTE', $quoteHtml);
    }

    public function test_ai_business_context_excludes_inactive_knowledge(): void
    {
        Business::current()->update([
            'name' => 'Knowledge Co',
            'description' => 'We sell widgets.',
            'ai_support_instructions' => 'Stay polite.',
        ]);
        BusinessOffering::factory()->create(['name' => 'Active Service', 'is_active' => true]);
        BusinessOffering::factory()->inactive()->create(['name' => 'Hidden Service']);
        BusinessKnowledgeArticle::factory()->create([
            'title' => 'Open hours',
            'content' => '9 to 5',
            'is_active' => true,
        ]);
        BusinessKnowledgeArticle::factory()->inactive()->create([
            'title' => 'Secret policy',
            'content' => 'Do not share',
        ]);

        $identity = app(ConversationService::class)->findOrCreateIdentity(
            \App\Enums\CommunicationChannel::WhatsApp,
            '2348099990000',
            'Unknown',
        );
        $conversation = app(ConversationService::class)->openConversation($identity, ConversationMode::Ai);

        $text = app(AiBusinessContextAssembler::class)->assemble(Business::current(), $conversation);

        $this->assertStringContainsString('We sell widgets.', $text);
        $this->assertStringContainsString('Active Service', $text);
        $this->assertStringNotContainsString('Hidden Service', $text);
        $this->assertStringContainsString('Open hours', $text);
        $this->assertStringNotContainsString('Secret policy', $text);
        $this->assertStringContainsString('No authorized customer is linked', $text);
    }

    public function test_knowledge_permissions_are_enforced(): void
    {
        $this->seedRolesAndPermissions();
        /** @var \App\Models\User $user */
        $user = \App\Models\User::factory()->create();
        $role = Role::findOrCreate('Limited', 'web');
        $role->syncPermissions([Permissions::SETTINGS_ACCESS]);
        $user->assignRole($role);

        $this->actingAs($user)->get(route('settings.knowledge.index'))->assertForbidden();

        $staff = $this->createStaffUser();
        $this->actingAs($staff)
            ->get(route('settings.knowledge.index'))
            ->assertOk();

        $this->actingAs($staff)
            ->post(route('settings.knowledge.offerings.store'), [
                'name' => 'Support retainer',
                'description' => 'Monthly support',
                'is_active' => true,
                'sort_order' => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('business_offerings', ['name' => 'Support retainer']);
    }

    public function test_inbound_whatsapp_document_is_stored_and_notifies_admin(): void
    {
        Notification::fake();
        config([
            'adman.whatsapp.enabled' => true,
            'adman.whatsapp.app_secret' => 'test-app-secret',
            'adman.whatsapp.webhook_verify_token' => 'verify-me',
        ]);

        $this->app->instance(WhatsAppMediaClient::class, new FakeWhatsAppMediaClient(
            binary: '%PDF-1.4 fake',
            mimeType: 'application/pdf',
        ));

        $staff = $this->createStaffUser();

        app(WhatsAppInboundService::class)->handlePayload([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'from' => '2348012349999',
                            'id' => 'wamid.DOCIN1',
                            'timestamp' => (string) time(),
                            'type' => 'document',
                            'document' => [
                                'id' => 'media.in.1',
                                'filename' => 'receipt.pdf',
                                'mime_type' => 'application/pdf',
                            ],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $attachment = MessageAttachment::query()->first();
        $this->assertNotNull($attachment);
        $this->assertSame('stored', $attachment->processing_status);
        $this->assertSame('receipt.pdf', $attachment->original_filename);
        $this->assertTrue(Storage::disk('local')->exists($attachment->path));
        $this->assertStringContainsString('forwarded to the team', (string) Message::query()->first()?->body);

        Notification::assertSentTo($staff, InboundAttachmentReceived::class);
        $this->assertDatabaseHas('audit_events', ['event' => 'attachment.inbound_stored']);
        $this->assertSame(0, \App\Models\Payment::query()->count());
    }

    public function test_oversized_inbound_media_is_rejected(): void
    {
        config(['adman.whatsapp.inbound_media_max_bytes' => 10]);
        $this->app->instance(WhatsAppMediaClient::class, new class implements WhatsAppMediaClient
        {
            public function download(string $mediaId): WhatsAppMediaDownloadResult
            {
                return WhatsAppMediaDownloadResult::ok(str_repeat('x', 50), 'application/pdf');
            }
        });

        app(WhatsAppInboundService::class)->handlePayload([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'from' => '2348012348888',
                            'id' => 'wamid.BIG1',
                            'timestamp' => (string) time(),
                            'type' => 'document',
                            'document' => [
                                'id' => 'media.big',
                                'filename' => 'huge.pdf',
                            ],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $attachment = MessageAttachment::query()->first();
        $this->assertNotNull($attachment);
        $this->assertSame('failed', $attachment->processing_status);
        $this->assertStringContainsString('maximum', (string) $attachment->failure_reason);
    }

    public function test_attachment_download_requires_permission(): void
    {
        Notification::fake();
        $this->app->instance(WhatsAppMediaClient::class, new FakeWhatsAppMediaClient(
            binary: 'image-bytes',
            mimeType: 'image/jpeg',
        ));

        app(WhatsAppInboundService::class)->handlePayload([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'from' => '2348012347777',
                            'id' => 'wamid.IMG1',
                            'timestamp' => (string) time(),
                            'type' => 'image',
                            'image' => ['id' => 'media.img'],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $attachment = MessageAttachment::query()->firstOrFail();
        $this->seedRolesAndPermissions();
        /** @var \App\Models\User $user */
        $user = \App\Models\User::factory()->create();
        $role = Role::findOrCreate('Limited', 'web');
        $role->syncPermissions([Permissions::CONVERSATIONS_VIEW, Permissions::MESSAGES_VIEW]);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('attachments.download', $attachment))
            ->assertForbidden();

        $staff = $this->createStaffUser();
        $this->actingAs($staff)
            ->get(route('attachments.download', $attachment))
            ->assertOk();
    }
}
