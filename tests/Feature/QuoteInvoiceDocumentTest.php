<?php

namespace Tests\Feature;

use App\Enums\ContactStatus;
use App\Enums\DiscountType;
use App\Enums\InvoiceLifecycleStatus;
use App\Enums\InvoicePaymentStatus;
use App\Enums\QuoteStatus;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use App\Services\DocumentService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\TestCase;

class QuoteInvoiceDocumentTest extends TestCase
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

    public function test_guests_cannot_view_quotes(): void
    {
        $this->get(route('quotes.index'))->assertRedirect(route('login'));
    }

    public function test_staff_can_create_quote_for_customer_only(): void
    {
        $staff = $this->createStaffUser();
        $customer = Contact::factory()->customer()->create();
        $unknown = Contact::factory()->unknown()->create();

        $this->actingAs($staff)
            ->post(route('quotes.store'), [
                'contact_id' => $unknown->id,
                'discount_type' => DiscountType::None->value,
                'discount_value' => '0',
                'tax_enabled' => false,
                'items' => $this->sampleItems(),
            ])
            ->assertSessionHasErrors('contact_id');

        $this->actingAs($staff)
            ->post(route('quotes.store'), [
                'contact_id' => $customer->id,
                'discount_type' => DiscountType::None->value,
                'discount_value' => '0',
                'tax_enabled' => false,
                'items' => $this->sampleItems(),
            ])
            ->assertRedirect();

        $quote = Quote::query()->first();
        $this->assertNotNull($quote);
        $this->assertSame(QuoteStatus::Draft, $quote->status);
        $this->assertSame('200.00', (string) $quote->total);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'quote.created',
            'auditable_id' => $quote->id,
        ]);
    }

    public function test_draft_quote_can_be_edited_and_issued(): void
    {
        $staff = $this->createStaffUser();
        $customer = Contact::factory()->customer()->create([
            'display_name' => 'Ada Customer',
            'email' => 'ada@example.com',
        ]);

        $this->actingAs($staff)->post(route('quotes.store'), [
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
            'items' => $this->sampleItems(),
        ]);

        $quote = Quote::query()->firstOrFail();

        $this->actingAs($staff)->put(route('quotes.update', $quote), [
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::Percentage->value,
            'discount_value' => '10',
            'tax_enabled' => true,
            'tax_rate' => '7.5',
            'items' => $this->sampleItems(),
        ])->assertRedirect();

        $quote->refresh();
        $this->assertSame('180.00', (string) $quote->taxable_subtotal);
        $this->assertSame('13.50', (string) $quote->tax_amount);
        $this->assertSame('193.50', (string) $quote->total);

        $this->actingAs($staff)
            ->post(route('quotes.issue', $quote))
            ->assertRedirect();

        $quote->refresh();
        $this->assertSame(QuoteStatus::Issued, $quote->status);
        $this->assertNotNull($quote->business_snapshot);
        $this->assertNotNull($quote->customer_snapshot);
        $this->assertSame('Ada Customer', $quote->customer_snapshot['display_name']);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'quote.issued',
            'auditable_id' => $quote->id,
        ]);
    }

    public function test_accept_reject_cancel_quote_lifecycle(): void
    {
        $staff = $this->createStaffUser();
        $quote = Quote::factory()->issued()->create();

        $this->actingAs($staff)
            ->post(route('quotes.accept', $quote))
            ->assertRedirect();
        $this->assertSame(QuoteStatus::Accepted, $quote->refresh()->status);

        $rejected = Quote::factory()->issued()->create();
        $this->actingAs($staff)
            ->post(route('quotes.reject', $rejected))
            ->assertRedirect();
        $this->assertSame(QuoteStatus::Rejected, $rejected->refresh()->status);

        $cancellable = Quote::factory()->issued()->create();
        $this->actingAs($staff)
            ->post(route('quotes.cancel', $cancellable))
            ->assertRedirect();
        $this->assertSame(QuoteStatus::Cancelled, $cancellable->refresh()->status);
    }

    public function test_unauthorized_quote_actions_fail(): void
    {
        $this->seedRolesAndPermissions();
        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::findOrCreate('QuoteViewer', 'web');
        $role->syncPermissions([Permissions::QUOTES_VIEW]);
        $user->assignRole($role);

        $quote = Quote::factory()->create();

        $this->actingAs($user)
            ->post(route('quotes.issue', $quote))
            ->assertForbidden();
    }

    public function test_accepted_quote_converts_idempotently_to_invoice(): void
    {
        $staff = $this->createStaffUser();
        $quote = Quote::factory()->accepted()->create();

        $this->actingAs($staff)
            ->post(route('quotes.convert', $quote))
            ->assertRedirect();

        $invoice = Invoice::query()->where('quote_id', $quote->id)->first();
        $this->assertNotNull($invoice);
        $this->assertNotSame($quote->number, $invoice->number);
        $this->assertSame($quote->contact_id, $invoice->contact_id);
        $this->assertSame((string) $quote->total, (string) $invoice->total);
        $this->assertSame(InvoiceLifecycleStatus::Draft, $invoice->lifecycle_status);
        $this->assertSame(InvoicePaymentStatus::Unpaid, $invoice->payment_status);

        $firstId = $invoice->id;

        $this->actingAs($staff)
            ->post(route('quotes.convert', $quote))
            ->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame(1, Invoice::query()->where('quote_id', $quote->id)->count());
        $this->assertSame($firstId, Invoice::query()->where('quote_id', $quote->id)->value('id'));
        $this->assertDatabaseHas('audit_events', [
            'event' => 'quote.converted',
            'auditable_id' => $quote->id,
        ]);
    }

    public function test_invoice_create_issue_cancel_and_unpaid_state(): void
    {
        $staff = $this->createStaffUser();
        $customer = Contact::factory()->customer()->create();

        $this->actingAs($staff)->post(route('invoices.store'), [
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::Fixed->value,
            'discount_value' => '20',
            'tax_enabled' => true,
            'tax_rate' => '10',
            'items' => $this->sampleItems(),
        ])->assertRedirect();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame('180.00', (string) $invoice->taxable_subtotal);
        $this->assertSame('18.00', (string) $invoice->tax_amount);
        $this->assertSame('198.00', (string) $invoice->total);

        $this->actingAs($staff)
            ->post(route('invoices.issue', $invoice))
            ->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoiceLifecycleStatus::Issued, $invoice->lifecycle_status);
        $this->assertSame(InvoicePaymentStatus::Unpaid, $invoice->payment_status);
        $this->assertSame('0.00', (string) $invoice->amount_paid);
        $this->assertSame((string) $invoice->total, (string) $invoice->balance_due);
        $this->assertNotNull($invoice->business_snapshot);
        $this->assertNotNull($invoice->due_date);

        $this->actingAs($staff)
            ->post(route('invoices.cancel', $invoice))
            ->assertRedirect();

        $this->assertSame(InvoiceLifecycleStatus::Cancelled, $invoice->refresh()->lifecycle_status);
    }

    public function test_issued_snapshots_survive_business_and_contact_changes(): void
    {
        $staff = $this->createStaffUser();
        $customer = Contact::factory()->customer()->create([
            'display_name' => 'Original Name',
            'email' => 'original@example.com',
        ]);

        Business::current()->update(['name' => 'Original Biz']);

        $this->actingAs($staff)->post(route('quotes.store'), [
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
            'items' => $this->sampleItems(),
        ]);

        $quote = Quote::query()->firstOrFail();
        $this->actingAs($staff)->post(route('quotes.issue', $quote));
        $quote->refresh();

        $originalTotal = (string) $quote->total;
        $originalBiz = $quote->business_snapshot['name'];
        $originalCustomer = $quote->customer_snapshot['display_name'];

        Business::current()->update(['name' => 'Changed Biz']);
        $customer->update(['display_name' => 'Changed Name', 'email' => 'changed@example.com']);

        $quote->refresh();
        $this->assertSame($originalBiz, $quote->business_snapshot['name']);
        $this->assertSame($originalCustomer, $quote->customer_snapshot['display_name']);
        $this->assertSame($originalTotal, (string) $quote->total);
        $this->assertSame('Original Name', $quote->customer_snapshot['display_name']);
    }

    public function test_issued_quote_cannot_be_silently_edited(): void
    {
        $staff = $this->createStaffUser();
        $quote = Quote::factory()->issued()->create();

        $this->actingAs($staff)
            ->put(route('quotes.update', $quote), [
                'contact_id' => $quote->contact_id,
                'discount_type' => DiscountType::None->value,
                'discount_value' => '0',
                'tax_enabled' => false,
                'items' => $this->sampleItems(),
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_quote_and_invoice_pdf_generation_and_secure_link(): void
    {
        Storage::fake('local');

        $staff = $this->createStaffUser();
        $quote = Quote::factory()->issued()->create();

        $response = $this->actingAs($staff)
            ->post(route('quotes.generate-document', $quote));

        $response->assertRedirect();
        $document = Document::query()->first();
        $this->assertNotNull($document);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'document.generated',
            'auditable_id' => $document->id,
        ]);

        $result = app(DocumentService::class)->createSecureLink($document, $staff);
        $token = $result['plain_token'];
        $this->assertNotNull($token);

        $this->get(route('documents.secure', $token))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->get(route('documents.secure', 'invalidtokeninvalidtokeninvalidtoken12'))
            ->assertNotFound();

        $this->actingAs($staff)
            ->post(route('documents.revoke-link', $document))
            ->assertRedirect();

        $this->get(route('documents.secure', $token))
            ->assertNotFound();
    }

    public function test_draft_documents_are_not_publicly_accessible(): void
    {
        Storage::fake('local');
        $staff = $this->createStaffUser();
        $quote = Quote::factory()->create(); // draft

        $this->actingAs($staff)
            ->post(route('quotes.generate-document', $quote))
            ->assertSessionHasErrors();

        $invoice = Invoice::factory()->create(); // draft
        $this->actingAs($staff)
            ->post(route('invoices.generate-document', $invoice))
            ->assertSessionHasErrors();
    }

    public function test_unauthorized_staff_cannot_access_documents_index(): void
    {
        $this->seedRolesAndPermissions();
        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::findOrCreate('NoDocs', 'web');
        $role->syncPermissions([Permissions::QUOTES_VIEW]);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('documents.index'))
            ->assertForbidden();
    }

    public function test_linking_does_not_promote_contact_and_quote_requires_customer(): void
    {
        $staff = $this->createStaffUser();
        $prospect = Contact::factory()->prospect()->create();

        $this->actingAs($staff)
            ->post(route('invoices.store'), [
                'contact_id' => $prospect->id,
                'discount_type' => DiscountType::None->value,
                'discount_value' => '0',
                'tax_enabled' => false,
                'items' => $this->sampleItems(),
            ])
            ->assertSessionHasErrors('contact_id');

        $this->assertSame(ContactStatus::Prospect, $prospect->refresh()->status);
    }

    public function test_convert_from_non_accepted_quote_fails(): void
    {
        $staff = $this->createStaffUser();
        $quote = Quote::factory()->issued()->create();

        $this->actingAs($staff)
            ->post(route('quotes.convert', $quote))
            ->assertSessionHasErrors('status');
    }
}
