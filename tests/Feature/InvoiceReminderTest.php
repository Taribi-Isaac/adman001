<?php

namespace Tests\Feature;

use App\Enums\CommunicationChannel;
use App\Enums\DiscountType;
use App\Enums\InvoiceLifecycleStatus;
use App\Enums\InvoicePaymentStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReminderChannelPreference;
use App\Enums\ReminderOccurrenceStatus;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\ReminderOccurrence;
use App\Models\ReminderRule;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\ReminderService;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\TestCase;

class InvoiceReminderTest extends TestCase
{
    use CreatesFoundationUsers;
    use RefreshDatabase;

    /**
     * @return list<array{description: string, quantity: string, unit_price: string}>
     */
    private function sampleItems(): array
    {
        return [
            [
                'description' => 'Service',
                'quantity' => '1',
                'unit_price' => '100.00',
            ],
        ];
    }

    private function issuedInvoice(array $contactAttrs = [], ?string $dueDate = null): array
    {
        $staff = $this->createStaffUser();
        $customer = Contact::factory()->customer()->create(array_merge([
            'email' => 'remind@example.com',
            'reminder_channel' => ReminderChannelPreference::Email,
        ], $contactAttrs));

        $invoice = app(InvoiceService::class)->create([
            'contact_id' => $customer->id,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
            'due_date' => $dueDate ?? now()->addDays(7)->toDateString(),
        ], $this->sampleItems(), $staff);

        $invoice = app(InvoiceService::class)->issue($invoice);

        return ['invoice' => $invoice->fresh(['contact']), 'staff' => $staff, 'customer' => $customer];
    }

    public function test_default_rules_are_created(): void
    {
        $rules = app(ReminderService::class)->ensureDefaultRules();
        $this->assertSame([-7, -2, 1], $rules->pluck('offset_days')->all());
    }

    public function test_admin_can_update_rules_and_permissions_enforced(): void
    {
        $staff = $this->createStaffUser();
        app(ReminderService::class)->ensureDefaultRules();
        $rules = ReminderRule::query()->orderBy('sort_order')->get();

        $this->actingAs($staff)->put(route('settings.automation.reminders.update'), [
            'enabled' => true,
            'rules' => [
                ['id' => $rules[0]->id, 'offset_days' => -10, 'is_enabled' => true],
                ['id' => $rules[1]->id, 'offset_days' => -3, 'is_enabled' => true],
                ['id' => $rules[2]->id, 'offset_days' => 2, 'is_enabled' => false],
            ],
        ])->assertRedirect();

        $this->assertSame(-10, $rules[0]->fresh()->offset_days);
        $this->assertFalse($rules[2]->fresh()->is_enabled);
        $this->assertDatabaseHas('audit_events', ['event' => 'reminder_rule.updated']);

        $this->seedRolesAndPermissions();
        /** @var User $limited */
        $limited = User::factory()->create();
        $role = Role::findOrCreate('Limited', 'web');
        $role->syncPermissions([Permissions::SETTINGS_ACCESS]);
        $limited->assignRole($role);

        $this->actingAs($limited)->put(route('settings.automation.reminders.update'), [
            'enabled' => false,
            'rules' => [['offset_days' => -7, 'is_enabled' => true]],
        ])->assertForbidden();
    }

    public function test_duplicate_offsets_rejected(): void
    {
        $staff = $this->createStaffUser();
        $this->actingAs($staff)->from(route('settings.automation.reminders.edit'))
            ->put(route('settings.automation.reminders.update'), [
                'enabled' => true,
                'rules' => [
                    ['offset_days' => -7, 'is_enabled' => true],
                    ['offset_days' => -7, 'is_enabled' => true],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('rules');
    }

    public function test_eligibility_skips_draft_cancelled_and_paid(): void
    {
        $service = app(ReminderService::class);
        ['invoice' => $invoice, 'staff' => $staff] = $this->issuedInvoice();

        $draft = Invoice::factory()->create(['lifecycle_status' => InvoiceLifecycleStatus::Draft]);
        $this->assertNotNull($service->ineligibilityReason($draft));

        app(InvoiceService::class)->cancel($invoice);
        $this->assertNotNull($service->ineligibilityReason($invoice->fresh()));

        ['invoice' => $paidInvoice, 'staff' => $staff2] = $this->issuedInvoice(['email' => 'paid@example.com']);
        app(PaymentService::class)->record($paidInvoice, [
            'amount' => $paidInvoice->total,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_date' => now()->toDateString(),
        ], $staff2, true);

        $this->assertNotNull($service->ineligibilityReason($paidInvoice->fresh()));
        $this->assertSame(InvoicePaymentStatus::Paid, $paidInvoice->fresh()->payment_status);
    }

    public function test_partial_payment_remains_eligible_and_uses_balance(): void
    {
        Mail::fake();
        Business::current()->update(['invoice_reminders_enabled' => true, 'email' => 'billing@test.com']);
        app(ReminderService::class)->ensureDefaultRules();

        $due = CarbonImmutable::now(Business::current()->timezone ?: 'UTC')->addDays(7)->toDateString();
        ['invoice' => $invoice, 'staff' => $staff] = $this->issuedInvoice([], $due);

        app(PaymentService::class)->record($invoice, [
            'amount' => '40.00',
            'payment_method' => PaymentMethod::Cash->value,
            'payment_date' => now()->toDateString(),
        ], $staff, true);

        $invoice->refresh();
        $this->assertNull(app(ReminderService::class)->ineligibilityReason($invoice));
        $this->assertSame('60.00', (string) $invoice->balance_due);

        $today = CarbonImmutable::parse($due, Business::current()->timezone ?: 'UTC')->subDays(7);
        $claimed = app(ReminderService::class)->processDue($today, dispatchJobs: false);
        $this->assertTrue($claimed->isNotEmpty());

        $occurrence = $claimed->first();
        app(ReminderService::class)->processOccurrence($occurrence);
        $this->assertSame(ReminderOccurrenceStatus::Queued, $occurrence->fresh()->status);
        Mail::assertSent(\App\Mail\DocumentOutboundMail::class);
    }

    public function test_timing_offsets_and_idempotency(): void
    {
        Business::current()->update(['invoice_reminders_enabled' => true, 'email' => 'billing@test.com']);
        app(ReminderService::class)->ensureDefaultRules();
        Mail::fake();

        $tz = Business::current()->timezone ?: 'UTC';
        $due = CarbonImmutable::parse('2026-10-20', $tz);
        ['invoice' => $invoice] = $this->issuedInvoice([], $due->toDateString());

        $minus7 = $due->subDays(7);
        $first = app(ReminderService::class)->processDue($minus7, dispatchJobs: false);
        $this->assertCount(1, $first);

        $again = app(ReminderService::class)->processDue($minus7, dispatchJobs: false);
        $this->assertCount(0, $again);

        $this->assertSame(1, ReminderOccurrence::query()
            ->where('invoice_id', $invoice->id)
            ->where('channel', CommunicationChannel::Email->value)
            ->count());

        $before = $due->subDays(10);
        $none = app(ReminderService::class)->processDue($before, dispatchJobs: false);
        $this->assertCount(0, $none);
    }

    public function test_paid_between_claim_and_process_skips(): void
    {
        Business::current()->update(['invoice_reminders_enabled' => true, 'email' => 'billing@test.com']);
        app(ReminderService::class)->ensureDefaultRules();

        $tz = Business::current()->timezone ?: 'UTC';
        $due = CarbonImmutable::now($tz)->addDays(7);
        ['invoice' => $invoice, 'staff' => $staff] = $this->issuedInvoice([], $due->toDateString());

        $claimed = app(ReminderService::class)->processDue($due->subDays(7), dispatchJobs: false);
        $occurrence = $claimed->first();
        $this->assertNotNull($occurrence);

        app(PaymentService::class)->record($invoice, [
            'amount' => $invoice->total,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_date' => now()->toDateString(),
        ], $staff, true);

        app(ReminderService::class)->processOccurrence($occurrence->fresh());
        $this->assertSame(ReminderOccurrenceStatus::Skipped, $occurrence->fresh()->status);
        $this->assertStringContainsString('paid', strtolower((string) $occurrence->fresh()->skip_reason));
    }

    public function test_missing_email_is_not_deliverable_and_whatsapp_opt_in_respected(): void
    {
        Business::current()->update([
            'invoice_reminders_enabled' => true,
            'outbound_whatsapp_enabled' => true,
        ]);
        config(['adman.whatsapp.enabled' => true, 'adman.whatsapp.access_token' => 't', 'adman.whatsapp.phone_number_id' => '1']);
        app(ReminderService::class)->ensureDefaultRules();

        $tz = Business::current()->timezone ?: 'UTC';
        $due = CarbonImmutable::now($tz)->addDays(7);

        ['invoice' => $noEmail] = $this->issuedInvoice([
            'email' => null,
            'reminder_channel' => ReminderChannelPreference::Email,
        ], $due->toDateString());

        $claimed = app(ReminderService::class)->processDue($due->subDays(7), dispatchJobs: false);
        $occurrence = $claimed->firstWhere('invoice_id', $noEmail->id);
        $this->assertNotNull($occurrence);
        app(ReminderService::class)->processOccurrence($occurrence);
        $this->assertSame(ReminderOccurrenceStatus::NotDeliverable, $occurrence->fresh()->status);

        ['invoice' => $waInvoice] = $this->issuedInvoice([
            'email' => 'wa@example.com',
            'whatsapp_id' => '2348012345678',
            'whatsapp_opt_in' => false,
            'reminder_channel' => ReminderChannelPreference::WhatsApp,
        ], $due->toDateString());

        $claimedWa = app(ReminderService::class)->processDue($due->subDays(7), dispatchJobs: false);
        $waOcc = $claimedWa->firstWhere('invoice_id', $waInvoice->id);
        $this->assertNotNull($waOcc);
        app(ReminderService::class)->processOccurrence($waOcc);
        $this->assertSame(ReminderOccurrenceStatus::NotDeliverable, $waOcc->fresh()->status);
    }

    public function test_both_channels_create_independent_occurrences(): void
    {
        Business::current()->update(['invoice_reminders_enabled' => true, 'email' => 'b@test.com']);
        app(ReminderService::class)->ensureDefaultRules();

        $tz = Business::current()->timezone ?: 'UTC';
        $due = CarbonImmutable::now($tz)->addDays(2);
        ['invoice' => $invoice] = $this->issuedInvoice([
            'email' => 'both@example.com',
            'whatsapp_id' => '2348099999999',
            'whatsapp_opt_in' => true,
            'reminder_channel' => ReminderChannelPreference::Both,
        ], $due->toDateString());

        $claimed = app(ReminderService::class)->processDue($due->subDays(2), dispatchJobs: false);
        $forInvoice = $claimed->where('invoice_id', $invoice->id);
        $this->assertCount(2, $forInvoice);
        $this->assertTrue($forInvoice->contains(fn ($o) => $o->channel === CommunicationChannel::Email));
        $this->assertTrue($forInvoice->contains(fn ($o) => $o->channel === CommunicationChannel::WhatsApp));
    }

    public function test_command_runs(): void
    {
        Queue::fake();
        Business::current()->update(['invoice_reminders_enabled' => true]);
        app(ReminderService::class)->ensureDefaultRules();

        $this->artisan('reminders:process-due')->assertSuccessful();
        Queue::assertPushed(\App\Jobs\ProcessDueInvoiceReminders::class);
    }
}
