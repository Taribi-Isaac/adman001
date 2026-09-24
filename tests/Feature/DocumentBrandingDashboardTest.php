<?php

namespace Tests\Feature;

use App\Enums\InvoiceLifecycleStatus;
use App\Enums\InvoicePaymentStatus;
use App\Enums\PaymentClaimStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentClaim;
use App\Models\Quote;
use App\Services\DocumentService;
use App\Support\DocumentPresentation;
use App\Support\DocumentSnapshots;
use Database\Seeders\BusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\TestCase;

class DocumentBrandingDashboardTest extends TestCase
{
    use CreatesFoundationUsers;
    use RefreshDatabase;

    public function test_authorized_user_can_upload_and_reset_business_logo(): void
    {
        Storage::fake('local');
        $this->seed(BusinessSeeder::class);
        $admin = $this->createSuperAdmin();

        $file = UploadedFile::fake()->image('logo.png', 120, 60);

        $this->actingAs($admin)
            ->post(route('settings.business.logo.update'), ['logo' => $file])
            ->assertRedirect();

        $business = Business::current()->fresh();
        $this->assertNotNull($business->logo_path);
        Storage::disk('local')->assertExists($business->logo_path);
        $this->assertDatabaseHas('audit_events', ['event' => 'business.logo_updated']);

        $this->actingAs($admin)
            ->get(route('settings.business.logo.show'))
            ->assertOk();

        $this->actingAs($admin)
            ->delete(route('settings.business.logo.destroy'))
            ->assertRedirect();

        $this->assertNull(Business::current()->fresh()->logo_path);
        $this->assertDatabaseHas('audit_events', ['event' => 'business.logo_removed']);
    }

    public function test_unauthorized_user_cannot_change_business_logo(): void
    {
        Storage::fake('local');
        $this->seed(BusinessSeeder::class);
        $staff = $this->createStaffUser();

        $this->actingAs($staff)
            ->post(route('settings.business.logo.update'), [
                'logo' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertForbidden();
    }

    public function test_invalid_logo_upload_is_rejected(): void
    {
        Storage::fake('local');
        $this->seed(BusinessSeeder::class);
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->post(route('settings.business.logo.update'), [
                'logo' => UploadedFile::fake()->create('malware.exe', 100, 'application/octet-stream'),
            ])
            ->assertSessionHasErrors('logo');
    }

    public function test_quote_pdf_renders_customer_fallback_and_default_logo(): void
    {
        Storage::fake('local');
        $staff = $this->createStaffUser();
        $customer = Contact::factory()->customer()->create([
            'display_name' => '',
            'organization_name' => 'Fallback Org Ltd',
            'first_name' => 'Ignored',
            'last_name' => 'Person',
            'phone' => null,
            'email' => null,
            'address_line_1' => null,
        ]);

        $quote = Quote::factory()->issued()->create([
            'contact_id' => $customer->id,
            'customer_snapshot' => DocumentSnapshots::customer($customer),
            'business_snapshot' => DocumentSnapshots::business(Business::current()),
            'notes' => null,
            'terms' => null,
            'discount_amount' => '0.00',
            'tax_enabled' => false,
        ]);

        $quote->items()->create([
            'position' => 1,
            'description' => str_repeat('Long description segment ', 12),
            'quantity' => '2.0000',
            'unit' => 'hrs',
            'unit_price' => '25.00',
            'line_subtotal' => '50.00',
        ]);

        $html = view('documents.quote', [
            'quote' => $quote->load('items'),
            'business' => $quote->business_snapshot,
            'customer' => $quote->customer_snapshot,
            'items' => $quote->items,
            'logoSrc' => DocumentPresentation::logoDataUri(
                is_string($quote->business_snapshot['logo_path'] ?? null)
                    ? $quote->business_snapshot['logo_path']
                    : null
            ),
        ])->render();

        $this->assertStringContainsString('Fallback Org Ltd', $html);
        $this->assertStringContainsString('data:image/', $html);
        $this->assertStringNotContainsString('float: left', $html);

        $result = app(DocumentService::class)->generateQuotePdf($quote, $staff, false);
        $this->assertNotNull($result['document']);
        $this->assertSame((string) $quote->total, (string) $quote->fresh()->total);
        Storage::disk('local')->assertExists($result['document']->path);
    }

    public function test_invoice_and_payment_acknowledgement_pdfs_generate(): void
    {
        Storage::fake('local');
        $staff = $this->createStaffUser();
        $customer = Contact::factory()->customer()->create(['display_name' => 'Invoice Customer']);
        $invoice = Invoice::factory()->issued()->create([
            'contact_id' => $customer->id,
            'total' => '150.00',
            'balance_due' => '150.00',
            'amount_paid' => '0.00',
            'business_snapshot' => DocumentSnapshots::business(Business::current()),
            'customer_snapshot' => DocumentSnapshots::customer($customer),
        ]);

        $invoicePdf = app(DocumentService::class)->generateInvoicePdf($invoice, $staff, false);
        $this->assertNotNull($invoicePdf['document']);

        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'contact_id' => $invoice->contact_id,
            'amount' => '50.00',
            'currency_code' => $invoice->currency_code,
            'payment_method' => PaymentMethod::BankTransfer,
            'status' => PaymentStatus::Confirmed,
            'confirmed_at' => now(),
            'confirmed_by' => $staff->id,
            'recorded_by' => $staff->id,
            'business_snapshot' => $invoice->business_snapshot,
            'customer_snapshot' => $invoice->customer_snapshot,
            'invoice_snapshot' => [
                'total' => $invoice->total,
                'amount_paid_after' => '50.00',
                'balance_due_after' => '100.00',
            ],
        ]);

        $ack = app(DocumentService::class)->generatePaymentAcknowledgementPdf($payment, $staff, false);
        $this->assertNotNull($ack['document']);

        $html = view('documents.payment-acknowledgement', [
            'payment' => $payment,
            'invoice' => $invoice,
            'business' => $payment->business_snapshot,
            'customer' => $payment->customer_snapshot,
            'amount_paid_after' => '50.00',
            'balance_due_after' => '100.00',
            'is_partial' => true,
            'invoice_total' => (string) $invoice->total,
            'logoSrc' => DocumentPresentation::logoDataUri(null),
        ])->render();

        $this->assertStringContainsString('Invoice Customer', $html);
        $this->assertStringContainsString('Partial payment', $html);
    }

    public function test_dashboard_shows_real_operational_counts(): void
    {
        $admin = $this->createSuperAdmin();
        $customer = Contact::factory()->customer()->create();

        Quote::factory()->issued()->create(['contact_id' => $customer->id]);
        Quote::factory()->create([
            'contact_id' => $customer->id,
            'status' => QuoteStatus::Draft,
        ]);

        Invoice::factory()->issued()->create([
            'contact_id' => $customer->id,
            'lifecycle_status' => InvoiceLifecycleStatus::Issued,
            'payment_status' => InvoicePaymentStatus::Unpaid,
            'balance_due' => '100.00',
            'due_date' => now()->subDays(3)->toDateString(),
        ]);

        Invoice::factory()->issued()->create([
            'contact_id' => $customer->id,
            'lifecycle_status' => InvoiceLifecycleStatus::Issued,
            'payment_status' => InvoicePaymentStatus::Unpaid,
            'balance_due' => '50.00',
            'due_date' => now()->addDays(10)->toDateString(),
        ]);

        $invoice = Invoice::factory()->issued()->create([
            'contact_id' => $customer->id,
            'balance_due' => '75.00',
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        PaymentClaim::factory()->create([
            'invoice_id' => $invoice->id,
            'contact_id' => $customer->id,
            'status' => PaymentClaimStatus::PendingVerification,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('metrics.0.key', 'outstanding_invoices')
                ->where('metrics.0.count', 3)
                ->where('metrics.1.key', 'overdue_invoices')
                ->where('metrics.1.count', 1)
                ->where('metrics.2.key', 'pending_payment_claims')
                ->where('metrics.2.count', 1)
                ->where('metrics.3.key', 'quotes_awaiting_response')
                ->where('metrics.3.count', 1)
                ->has('metrics', 5)
                ->where('greeting.business_name', Business::current()->name)
            );
    }

    public function test_guests_cannot_access_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_issued_quote_snapshot_keeps_prior_logo_path_after_business_logo_change(): void
    {
        Storage::fake('local');
        $this->createStaffUser();
        $admin = $this->createSuperAdmin();

        Storage::disk('local')->put('branding/logo.png', 'fake-png-bytes');
        Business::current()->update(['logo_path' => 'branding/logo.png']);

        $quote = Quote::factory()->issued()->create([
            'business_snapshot' => DocumentSnapshots::business(Business::current()),
        ]);

        $this->assertSame('branding/logo.png', $quote->business_snapshot['logo_path']);

        $this->actingAs($admin)->delete(route('settings.business.logo.destroy'));

        $quote->refresh();
        $this->assertSame('branding/logo.png', $quote->business_snapshot['logo_path']);

        $this->assertSame(
            public_path(DocumentPresentation::DEFAULT_LOGO_FILENAME),
            DocumentPresentation::resolveLogoAbsolutePath($quote->business_snapshot['logo_path'])
        );
    }
}
