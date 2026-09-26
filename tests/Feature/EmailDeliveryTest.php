<?php

namespace Tests\Feature;

use App\Contracts\EmailDeliveryAdapter;
use App\Enums\CommunicationChannel;
use App\Enums\ContactStatus;
use App\Enums\DiscountType;
use App\Enums\MessageStatus;
use App\Enums\PaymentMethod;
use App\Enums\QuoteStatus;
use App\Jobs\SendOutboundEmailJob;
use App\Mail\DocumentOutboundMail;
use App\Models\Business;
use App\Models\CommunicationIdentity;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Quote;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\DocumentService;
use App\Services\EmailOutboundService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\QuoteService;
use App\Support\EmailDeliveryPayload;
use App\Support\EmailDeliveryResult;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\TestCase;

class EmailDeliveryTest extends TestCase
{
    use CreatesFoundationUsers;
    use RefreshDatabase;

    /**
     * @return list<array{description: string, quantity: string, unit_price: string, unit?: string|null}>
     */
    private function sampleItems(): array
    {
        return [
            [
                'description' => 'Consulting',
                'quantity' => '2',
                'unit' => 'hrs',
                'unit_price' => '100.00',
            ],
        ];
    }

    private function customerWithEmail(string $email = 'customer@example.com'): Contact
    {
        return Contact::factory()->customer()->create([
            'email' => $email,
            'display_name' => 'Acme Customer',
        ]);
    }

    /**
     * @return array{quote: Quote, staff: User}
     */
    private function issuedQuote(string $email = 'quote-customer@example.com'): array
    {
        $staff = $this->createStaffUser();
        $customer = $this->customerWithEmail($email);
        $quote = app(QuoteService::class)->create([
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff);
        $quote = app(QuoteService::class)->issue($quote);

        return ['quote' => $quote->fresh(['contact', 'documents']), 'staff' => $staff];
    }

    /**
     * @return array{invoice: Invoice, staff: User}
     */
    private function issuedInvoice(string $email = 'invoice-customer@example.com'): array
    {
        $staff = $this->createStaffUser();
        $customer = $this->customerWithEmail($email);
        $invoice = app(InvoiceService::class)->create([
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff);
        $invoice = app(InvoiceService::class)->issue($invoice);

        return ['invoice' => $invoice->fresh(['contact', 'documents']), 'staff' => $staff];
    }

    public function test_email_identity_is_created_and_reused(): void
    {
        $service = app(ConversationService::class);
        $contact = $this->customerWithEmail('Reuse.Me@Example.com');

        $first = $service->findOrCreateIdentity(
            CommunicationChannel::Email,
            'Reuse.Me@Example.com',
            $contact->display_name,
            $contact,
        );
        $second = $service->findOrCreateIdentity(
            CommunicationChannel::Email,
            'reuse.me@example.com',
            $contact->display_name,
            $contact,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame('reuse.me@example.com', $first->external_id);
        $this->assertSame($contact->id, $first->contact_id);
        $this->assertSame(1, CommunicationIdentity::query()->where('channel', 'email')->count());
    }

    public function test_authorized_staff_can_queue_invoice_email(): void
    {
        Mail::fake();
        ['invoice' => $invoice, 'staff' => $staff] = $this->issuedInvoice();
        Business::current()->update(['email' => 'billing@adman.test']);

        $this->actingAs($staff)
            ->post(route('invoices.send-email', $invoice))
            ->assertRedirect()
            ->assertSessionHas('success');

        $message = Message::query()->first();
        $this->assertNotNull($message);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame(CommunicationChannel::Email, $message->channel);
        $this->assertNotNull($message->document_id);
        $this->assertStringContainsString('/d/', (string) ($message->meta['secure_url'] ?? ''));

        Mail::assertSent(DocumentOutboundMail::class, function (DocumentOutboundMail $mail) {
            return str_contains($mail->envelope()->subject, 'Invoice');
        });

        $this->assertDatabaseHas('audit_events', ['event' => 'email.queued']);
        $this->assertDatabaseHas('audit_events', ['event' => 'email.sent']);
    }

    public function test_unauthorized_user_cannot_send_email(): void
    {
        $this->seedRolesAndPermissions();
        ['invoice' => $invoice] = $this->issuedInvoice();

        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::findOrCreate('Limited', 'web');
        $role->syncPermissions([Permissions::INVOICES_VIEW, Permissions::SETTINGS_ACCESS]);
        $user->assignRole($role);

        $this->actingAs($user)
            ->post(route('invoices.send-email', $invoice))
            ->assertForbidden();
    }

    public function test_missing_email_is_rejected(): void
    {
        $staff = $this->createStaffUser();
        $customer = Contact::factory()->customer()->create(['email' => null]);
        $invoice = app(InvoiceService::class)->create([
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff);
        $invoice = app(InvoiceService::class)->issue($invoice);

        $this->actingAs($staff)
            ->from(route('invoices.show', $invoice))
            ->post(route('invoices.send-email', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHasErrors('email');
    }

    public function test_draft_documents_cannot_be_emailed(): void
    {
        $staff = $this->createStaffUser();
        $customer = $this->customerWithEmail();
        $quote = app(QuoteService::class)->create([
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff);

        $this->assertSame(QuoteStatus::Draft, $quote->status);

        $this->actingAs($staff)
            ->from(route('quotes.show', $quote))
            ->post(route('quotes.send-email', $quote))
            ->assertRedirect()
            ->assertSessionHasErrors('status');
    }

    public function test_quote_and_payment_acknowledgement_emails_include_secure_link(): void
    {
        Mail::fake();
        Business::current()->update(['email' => 'billing@adman.test']);

        ['quote' => $quote, 'staff' => $staff] = $this->issuedQuote();

        $this->actingAs($staff)->post(route('quotes.send-email', $quote))->assertRedirect();

        $quoteMessage = Message::query()->where('template_key', 'quote')->first();
        $this->assertNotNull($quoteMessage);
        $this->assertStringContainsString('/d/', (string) ($quoteMessage->meta['secure_url'] ?? ''));

        ['invoice' => $invoice] = $this->issuedInvoice();
        $payment = app(PaymentService::class)->record($invoice, [
            'amount' => $invoice->total,
            'payment_method' => PaymentMethod::BankTransfer->value,
            'payment_date' => now()->toDateString(),
        ], $staff, true);

        $this->actingAs($staff)
            ->post(route('payments.send-email', $payment))
            ->assertRedirect();

        $ack = Message::query()->where('template_key', 'payment_acknowledgement')->first();
        $this->assertNotNull($ack);
        $this->assertSame(MessageStatus::Sent, $ack->status);
        $this->assertStringContainsString('/d/', (string) ($ack->meta['secure_url'] ?? ''));
        Mail::assertSent(DocumentOutboundMail::class, 2);
    }

    public function test_email_job_is_dispatched_and_appears_in_conversation(): void
    {
        Queue::fake();
        Business::current()->update(['email' => 'billing@adman.test']);
        ['invoice' => $invoice, 'staff' => $staff] = $this->issuedInvoice();

        $message = app(EmailOutboundService::class)->queueInvoiceEmail($invoice, $staff);

        Queue::assertPushed(SendOutboundEmailJob::class, fn (SendOutboundEmailJob $job) => $job->messageId === $message->id);
        $this->assertSame(MessageStatus::Pending, $message->fresh()->status);

        $this->assertDatabaseHas('conversations', [
            'id' => $message->conversation_id,
            'channel' => CommunicationChannel::Email->value,
            'contact_id' => $invoice->contact_id,
        ]);

        $this->actingAs($staff)
            ->get(route('conversations.show', $message->conversation_id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('messages', 1)
                ->where('messages.0.id', $message->id)
                ->where('messages.0.channel', 'email'));
    }

    public function test_failed_provider_submission_records_failure_and_allows_retry(): void
    {
        Business::current()->update(['email' => 'billing@adman.test']);
        $this->app->instance(EmailDeliveryAdapter::class, new class implements EmailDeliveryAdapter
        {
            public int $calls = 0;

            public function send(EmailDeliveryPayload $payload): EmailDeliveryResult
            {
                $this->calls++;

                return $this->calls === 1
                    ? EmailDeliveryResult::failed('Provider rejected the message.')
                    : EmailDeliveryResult::ok('provider-msg-2');
            }
        });

        ['invoice' => $invoice, 'staff' => $staff] = $this->issuedInvoice();
        $message = app(EmailOutboundService::class)->queueInvoiceEmail($invoice, $staff);

        $this->assertSame(MessageStatus::Failed, $message->fresh()->status);
        $this->assertNotNull($message->fresh()->failure_reason);
        $this->assertDatabaseHas('audit_events', ['event' => 'email.failed']);

        $this->actingAs($staff)
            ->post(route('messages.retry-email', $message))
            ->assertRedirect();

        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);
        $this->assertSame(1, Message::query()->count());
        $this->assertDatabaseHas('audit_events', ['event' => 'email.retry_queued']);
    }

    public function test_repeated_delivery_of_sent_message_is_idempotent(): void
    {
        Mail::fake();
        Business::current()->update(['email' => 'billing@adman.test']);
        ['invoice' => $invoice, 'staff' => $staff] = $this->issuedInvoice();

        $message = app(EmailOutboundService::class)->queueInvoiceEmail($invoice, $staff);
        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);

        app(EmailOutboundService::class)->deliverQueuedMessage($message->fresh());
        app(EmailOutboundService::class)->deliverQueuedMessage($message->fresh());

        $this->assertSame(1, Message::query()->count());
        Mail::assertSent(DocumentOutboundMail::class, 1);
    }

    public function test_revoked_secure_link_behavior_remains_correct_after_email(): void
    {
        Mail::fake();
        Business::current()->update(['email' => 'billing@adman.test']);
        ['invoice' => $invoice, 'staff' => $staff] = $this->issuedInvoice();

        $message = app(EmailOutboundService::class)->queueInvoiceEmail($invoice, $staff);
        $document = Document::query()->findOrFail($message->document_id);
        $url = (string) ($message->meta['secure_url'] ?? '');
        $token = basename(parse_url($url, PHP_URL_PATH) ?: '');

        app(DocumentService::class)->revokeSecureLink($document, $staff);

        $this->get(route('documents.secure', $token))->assertNotFound();
        $this->assertFalse($document->fresh()->isAccessActive());
    }

    public function test_prospect_cannot_receive_document_email(): void
    {
        $staff = $this->createStaffUser();
        $prospect = Contact::factory()->create([
            'status' => ContactStatus::Prospect,
            'email' => 'prospect@example.com',
        ]);

        // Force an invoice-like path via service validation by attaching a fake invoice contact.
        $invoice = app(InvoiceService::class)->create([
            'contact_id' => Contact::factory()->customer()->create(['email' => 'ok@example.com'])->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems(), $staff);
        $invoice = app(InvoiceService::class)->issue($invoice);
        $invoice->contact_id = $prospect->id;
        $invoice->save();

        $this->expectException(ValidationException::class);
        app(EmailOutboundService::class)->queueInvoiceEmail($invoice->fresh(['contact', 'documents']), $staff);
    }
}
