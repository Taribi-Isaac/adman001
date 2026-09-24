<?php

namespace Tests\Feature;

use App\Enums\InvoiceLifecycleStatus;
use App\Enums\InvoicePaymentStatus;
use App\Enums\PaymentClaimStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentClaim;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\PaymentService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use CreatesFoundationUsers;
    use RefreshDatabase;

    public function test_guests_cannot_view_payments(): void
    {
        $this->get(route('payments.index'))->assertRedirect(route('login'));
    }

    public function test_unauthorized_staff_cannot_record_payment(): void
    {
        $this->seedRolesAndPermissions();
        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::findOrCreate('PayViewer', 'web');
        $role->syncPermissions([Permissions::PAYMENTS_VIEW]);
        $user->assignRole($role);

        $invoice = Invoice::factory()->issued()->create();

        $this->actingAs($user)
            ->post(route('payments.store'), [
                'invoice_id' => $invoice->id,
                'amount' => '10.00',
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now()->toDateString(),
            ])
            ->assertForbidden();
    }

    public function test_staff_can_record_and_confirm_payment(): void
    {
        $staff = $this->createStaffUser();
        $invoice = Invoice::factory()->issued()->create([
            'total' => '100.00',
            'balance_due' => '100.00',
            'amount_paid' => '0.00',
        ]);

        $this->actingAs($staff)->post(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => '40.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
            'payment_date' => now()->toDateString(),
            'reference' => 'TRX-1',
            'confirm_immediately' => true,
        ])->assertRedirect();

        $payment = Payment::query()->first();
        $this->assertNotNull($payment);
        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertSame('40.00', (string) $payment->amount);

        $invoice->refresh();
        $this->assertSame(InvoicePaymentStatus::PartiallyPaid, $invoice->payment_status);
        $this->assertSame('40.00', (string) $invoice->amount_paid);
        $this->assertSame('60.00', (string) $invoice->balance_due);

        $this->assertDatabaseHas('audit_events', [
            'event' => 'payment.confirmed',
            'auditable_id' => $payment->id,
        ]);
    }

    public function test_draft_and_cancelled_invoices_reject_payments(): void
    {
        $staff = $this->createStaffUser();
        $draft = Invoice::factory()->create();
        $cancelled = Invoice::factory()->issued()->create([
            'lifecycle_status' => InvoiceLifecycleStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        $this->actingAs($staff)->post(route('payments.store'), [
            'invoice_id' => $draft->id,
            'amount' => '10.00',
            'payment_method' => PaymentMethod::Cash->value,
            'payment_date' => now()->toDateString(),
        ])->assertSessionHasErrors('invoice_id');

        $this->actingAs($staff)->post(route('payments.store'), [
            'invoice_id' => $cancelled->id,
            'amount' => '10.00',
            'payment_method' => PaymentMethod::Cash->value,
            'payment_date' => now()->toDateString(),
        ])->assertSessionHasErrors('invoice_id');
    }

    public function test_payment_cannot_exceed_outstanding_balance(): void
    {
        $staff = $this->createStaffUser();
        $invoice = Invoice::factory()->issued()->create([
            'total' => '100.00',
            'balance_due' => '100.00',
            'amount_paid' => '0.00',
        ]);

        $this->actingAs($staff)->post(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => '150.00',
            'payment_method' => PaymentMethod::Cash->value,
            'payment_date' => now()->toDateString(),
            'confirm_immediately' => true,
        ])->assertSessionHasErrors('amount');
    }

    public function test_partial_then_settling_payments(): void
    {
        $staff = $this->createStaffUser();
        $invoice = Invoice::factory()->issued()->create([
            'total' => '100.00',
            'balance_due' => '100.00',
            'amount_paid' => '0.00',
        ]);

        $this->actingAs($staff)->post(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => '30.00',
            'payment_method' => PaymentMethod::Cash->value,
            'payment_date' => now()->toDateString(),
            'confirm_immediately' => true,
        ])->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoicePaymentStatus::PartiallyPaid, $invoice->payment_status);
        $this->assertSame('70.00', (string) $invoice->balance_due);

        $this->actingAs($staff)->post(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => '70.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
            'payment_date' => now()->toDateString(),
            'confirm_immediately' => true,
        ])->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoicePaymentStatus::Paid, $invoice->payment_status);
        $this->assertSame('100.00', (string) $invoice->amount_paid);
        $this->assertSame('0.00', (string) $invoice->balance_due);
        $this->assertSame(2, Payment::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_claim_does_not_affect_balance_until_confirmed(): void
    {
        $staff = $this->createStaffUser();
        $invoice = Invoice::factory()->issued()->create([
            'total' => '100.00',
            'balance_due' => '100.00',
            'amount_paid' => '0.00',
        ]);

        $this->actingAs($staff)->post(route('payment-claims.store'), [
            'invoice_id' => $invoice->id,
            'claimed_amount' => '50.00',
            'claimed_payment_date' => now()->toDateString(),
            'payment_method' => PaymentMethod::BankTransfer->value,
            'customer_reference' => 'CLAIM-1',
        ])->assertRedirect();

        $claim = PaymentClaim::query()->firstOrFail();
        $invoice->refresh();
        $this->assertSame(InvoicePaymentStatus::Unpaid, $invoice->payment_status);
        $this->assertSame('100.00', (string) $invoice->balance_due);
        $this->assertSame(PaymentClaimStatus::PendingVerification, $claim->status);

        $this->actingAs($staff)
            ->post(route('payment-claims.reject', $claim), [
                'reviewer_notes' => 'No proof',
            ])
            ->assertRedirect();

        $this->assertSame(0, Payment::query()->count());
        $invoice->refresh();
        $this->assertSame('100.00', (string) $invoice->balance_due);
    }

    public function test_confirming_claim_creates_payment_idempotently(): void
    {
        $staff = $this->createStaffUser();
        $invoice = Invoice::factory()->issued()->create([
            'total' => '100.00',
            'balance_due' => '100.00',
            'amount_paid' => '0.00',
        ]);

        $this->actingAs($staff)->post(route('payment-claims.store'), [
            'invoice_id' => $invoice->id,
            'claimed_amount' => '50.00',
            'claimed_payment_date' => now()->toDateString(),
            'payment_method' => PaymentMethod::BankTransfer->value,
        ]);

        $claim = PaymentClaim::query()->firstOrFail();

        $this->actingAs($staff)
            ->post(route('payment-claims.confirm', $claim))
            ->assertRedirect();

        $claim->refresh();
        $this->assertSame(PaymentClaimStatus::Confirmed, $claim->status);
        $this->assertNotNull($claim->payment_id);
        $this->assertSame(1, Payment::query()->count());

        $invoice->refresh();
        $this->assertSame(InvoicePaymentStatus::PartiallyPaid, $invoice->payment_status);
        $this->assertSame('50.00', (string) $invoice->amount_paid);

        $paymentId = $claim->payment_id;

        $this->actingAs($staff)
            ->post(route('payment-claims.confirm', $claim))
            ->assertRedirect();

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame($paymentId, $claim->refresh()->payment_id);
    }

    public function test_due_state_remains_independent_of_payment_state(): void
    {
        $staff = $this->createStaffUser();
        $invoice = Invoice::factory()->issued()->create([
            'total' => '100.00',
            'balance_due' => '100.00',
            'amount_paid' => '0.00',
            'due_date' => now()->subDays(5)->toDateString(),
        ]);

        $this->assertSame('overdue', $invoice->dueState()->value);
        $this->assertSame('overdue', $invoice->displayStatus()['key']);

        $this->actingAs($staff)->post(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => '25.00',
            'payment_method' => PaymentMethod::Cash->value,
            'payment_date' => now()->toDateString(),
            'confirm_immediately' => true,
        ]);

        $invoice->refresh();
        $this->assertSame(InvoicePaymentStatus::PartiallyPaid, $invoice->payment_status);
        $this->assertSame('overdue', $invoice->dueState()->value);
        // Display precedence: overdue wins over partially paid
        $this->assertSame('overdue', $invoice->displayStatus()['key']);
    }

    public function test_paid_invoice_due_state_becomes_not_applicable(): void
    {
        $staff = $this->createStaffUser();
        $invoice = Invoice::factory()->issued()->create([
            'total' => '100.00',
            'balance_due' => '100.00',
            'amount_paid' => '0.00',
            'due_date' => now()->subDays(3)->toDateString(),
        ]);

        $this->actingAs($staff)->post(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => '100.00',
            'payment_method' => PaymentMethod::Cash->value,
            'payment_date' => now()->toDateString(),
            'confirm_immediately' => true,
        ]);

        $invoice->refresh();
        $this->assertSame(InvoicePaymentStatus::Paid, $invoice->payment_status);
        $this->assertSame('not_applicable', $invoice->dueState()->value);
        $this->assertSame('paid', $invoice->displayStatus()['key']);
    }

    public function test_acknowledgement_generation_for_confirmed_payment(): void
    {
        Storage::fake('local');
        $staff = $this->createStaffUser();
        $invoice = Invoice::factory()->issued()->create([
            'total' => '100.00',
            'balance_due' => '100.00',
            'amount_paid' => '0.00',
        ]);

        $this->actingAs($staff)->post(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => '40.00',
            'payment_method' => PaymentMethod::Cash->value,
            'payment_date' => now()->toDateString(),
            'confirm_immediately' => true,
        ]);

        $payment = Payment::query()->firstOrFail();

        $this->actingAs($staff)
            ->post(route('payments.generate-acknowledgement', $payment))
            ->assertRedirect();

        $document = Document::query()->first();
        $this->assertNotNull($document);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'document.generated',
            'auditable_id' => $document->id,
        ]);

        $result = app(DocumentService::class)->createSecureLink($document, $staff);
        $this->get(route('documents.secure', $result['plain_token']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_pending_payment_confirm_and_reject_authorization(): void
    {
        $staff = $this->createStaffUser();
        $invoice = Invoice::factory()->issued()->create([
            'total' => '100.00',
            'balance_due' => '100.00',
            'amount_paid' => '0.00',
        ]);

        $this->actingAs($staff)->post(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => '20.00',
            'payment_method' => PaymentMethod::Other->value,
            'payment_date' => now()->toDateString(),
            'confirm_immediately' => false,
        ]);

        $payment = Payment::query()->firstOrFail();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $invoice->refresh();
        $this->assertSame(InvoicePaymentStatus::Unpaid, $invoice->payment_status);

        $this->actingAs($staff)->post(route('payments.confirm', $payment))->assertRedirect();
        $this->assertSame(PaymentStatus::Confirmed, $payment->refresh()->status);

        $pending = app(PaymentService::class)->record($invoice->fresh(), [
            'amount' => '10.00',
            'payment_method' => PaymentMethod::Cash->value,
            'payment_date' => now()->toDateString(),
        ], $staff, false);

        $this->actingAs($staff)->post(route('payments.reject', $pending), [
            'rejection_notes' => 'Wrong amount',
        ])->assertRedirect();

        $this->assertSame(PaymentStatus::Rejected, $pending->refresh()->status);
        $this->assertSame(1, Payment::query()->where('status', PaymentStatus::Confirmed)->count());
    }

    public function test_cannot_cancel_invoice_with_confirmed_payments(): void
    {
        $staff = $this->createStaffUser();
        $invoice = Invoice::factory()->issued()->create([
            'total' => '100.00',
            'balance_due' => '100.00',
            'amount_paid' => '0.00',
        ]);

        $this->actingAs($staff)->post(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => '10.00',
            'payment_method' => PaymentMethod::Cash->value,
            'payment_date' => now()->toDateString(),
            'confirm_immediately' => true,
        ]);

        $this->actingAs($staff)
            ->post(route('invoices.cancel', $invoice))
            ->assertSessionHasErrors('lifecycle_status');
    }
}
