<?php

namespace Tests\Feature;

use App\Enums\DiscountType;
use App\Enums\InvoiceLifecycleStatus;
use App\Enums\InvoicePaymentStatus;
use App\Enums\RecurringBillingFrequency;
use App\Enums\RecurringBillingGenerationStatus;
use App\Enums\RecurringBillingStatus;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\RecurringBillingGeneration;
use App\Models\RecurringBillingSchedule;
use App\Models\User;
use App\Services\RecurringBillingService;
use App\Support\Permissions;
use App\Support\RecurringBillingCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesFoundationUsers;
use Tests\TestCase;

class RecurringBillingTest extends TestCase
{
    use CreatesFoundationUsers;
    use RefreshDatabase;

    /**
     * @return list<array{description: string, quantity: string, unit_price: string, unit?: string|null}>
     */
    private function sampleItems(string $price = '100.00'): array
    {
        return [
            [
                'description' => 'Monthly retainer',
                'quantity' => '1',
                'unit' => 'mo',
                'unit_price' => $price,
            ],
        ];
    }

    public function test_guests_cannot_view_schedules(): void
    {
        $this->get(route('recurring-billing.index'))->assertRedirect(route('login'));
    }

    public function test_only_customers_can_have_schedules(): void
    {
        $staff = $this->createStaffUser();
        $prospect = Contact::factory()->prospect()->create();

        $this->actingAs($staff)->post(route('recurring-billing.store'), [
            'contact_id' => $prospect->id,
            'frequency' => RecurringBillingFrequency::Monthly->value,
            'start_date' => now()->toDateString(),
            'payment_term_days' => 14,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
            'items' => $this->sampleItems(),
        ])->assertSessionHasErrors('contact_id');
    }

    public function test_staff_can_create_pause_resume_cancel_schedule(): void
    {
        $staff = $this->createStaffUser();
        $customer = Contact::factory()->customer()->create();

        $this->actingAs($staff)->post(route('recurring-billing.store'), [
            'contact_id' => $customer->id,
            'frequency' => RecurringBillingFrequency::Monthly->value,
            'start_date' => now()->toDateString(),
            'payment_term_days' => 14,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
            'items' => $this->sampleItems(),
        ])->assertRedirect();

        $schedule = RecurringBillingSchedule::query()->firstOrFail();
        $this->assertSame(RecurringBillingStatus::Active, $schedule->status);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'recurring_billing.created',
            'auditable_id' => $schedule->id,
        ]);

        $this->actingAs($staff)->post(route('recurring-billing.pause', $schedule))->assertRedirect();
        $this->assertSame(RecurringBillingStatus::Paused, $schedule->refresh()->status);

        $this->actingAs($staff)->post(route('recurring-billing.resume', $schedule))->assertRedirect();
        $this->assertSame(RecurringBillingStatus::Active, $schedule->refresh()->status);

        $this->actingAs($staff)->post(route('recurring-billing.cancel', $schedule))->assertRedirect();
        $this->assertSame(RecurringBillingStatus::Cancelled, $schedule->refresh()->status);
        $this->assertNull($schedule->next_generation_date);
    }

    public function test_generation_creates_issued_independent_invoice(): void
    {
        $staff = $this->createStaffUser();
        $schedule = RecurringBillingSchedule::factory()->create([
            'start_date' => now()->toDateString(),
            'next_generation_date' => now()->toDateString(),
            'payment_term_days' => 10,
        ]);

        $this->actingAs($staff)
            ->post(route('recurring-billing.generate', $schedule))
            ->assertRedirect();

        $generation = RecurringBillingGeneration::query()->firstOrFail();
        $this->assertSame(RecurringBillingGenerationStatus::Succeeded, $generation->status);
        $this->assertNotNull($generation->invoice_id);

        $invoice = Invoice::query()->findOrFail($generation->invoice_id);
        $this->assertSame(InvoiceLifecycleStatus::Issued, $invoice->lifecycle_status);
        $this->assertSame(InvoicePaymentStatus::Unpaid, $invoice->payment_status);
        $this->assertSame('100.00', (string) $invoice->total);
        $this->assertNotNull($invoice->business_snapshot);
        $this->assertNotNull($invoice->number);

        $schedule->refresh();
        $this->assertNotNull($schedule->next_generation_date);
        $this->assertTrue($schedule->next_generation_date->gt(now()->startOfDay()));
    }

    public function test_idempotent_generation_for_same_period(): void
    {
        $staff = $this->createStaffUser();
        $schedule = RecurringBillingSchedule::factory()->create([
            'next_generation_date' => now()->toDateString(),
        ]);

        $service = app(RecurringBillingService::class);
        $first = $service->generateForSchedule($schedule, 'manual', $staff);
        $second = $service->generateForSchedule($schedule->fresh(), 'manual', $staff);

        // First period succeeded; second call advances to a new period OR returns same if still same period.
        // After first success, next_generation_date advances, so second generates a NEW period or skips if future.
        $this->assertSame(RecurringBillingGenerationStatus::Succeeded, $first->status);

        // Force same period retry — must not duplicate invoice
        $retry = $service->generateForSchedule($schedule->fresh(), 'retry', $staff, $first->period_key);
        $this->assertSame($first->invoice_id, $retry->invoice_id);
        $this->assertSame(1, Invoice::query()->where('id', $first->invoice_id)->count());
        $this->assertSame(
            1,
            RecurringBillingGeneration::query()
                ->where('schedule_id', $schedule->id)
                ->where('period_key', $first->period_key)
                ->count(),
        );
    }

    public function test_paused_and_cancelled_do_not_generate(): void
    {
        $staff = $this->createStaffUser();
        $paused = RecurringBillingSchedule::factory()->paused()->create([
            'next_generation_date' => now()->toDateString(),
        ]);
        $cancelled = RecurringBillingSchedule::factory()->cancelled()->create();

        $service = app(RecurringBillingService::class);
        $this->assertSame(
            RecurringBillingGenerationStatus::Skipped,
            $service->generateForSchedule($paused, 'scheduler')->status,
        );
        $this->assertSame(
            RecurringBillingGenerationStatus::Skipped,
            $service->generateForSchedule($cancelled, 'scheduler')->status,
        );
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_future_only_price_change(): void
    {
        $staff = $this->createStaffUser();
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00'));

        $schedule = RecurringBillingSchedule::factory()->create([
            'start_date' => '2026-03-15',
            'next_generation_date' => '2026-03-15',
        ]);

        $service = app(RecurringBillingService::class);
        $first = $service->generateForSchedule($schedule, 'manual', $staff);
        $firstInvoice = Invoice::query()->findOrFail($first->invoice_id);
        $this->assertSame('100.00', (string) $firstInvoice->total);
        $this->assertSame('2026-03', $first->period_key);

        $service->update($schedule->fresh(), [
            'contact_id' => $schedule->contact_id,
            'frequency' => $schedule->frequency->value,
            'payment_term_days' => $schedule->payment_term_days,
            'discount_type' => DiscountType::None->value,
            'discount_value' => '0',
            'tax_enabled' => false,
        ], $this->sampleItems('250.00'), $staff);

        Carbon::setTestNow(Carbon::parse('2026-04-15 12:00:00'));
        $schedule->fresh()->update(['next_generation_date' => '2026-04-15']);

        $second = $service->generateForSchedule($schedule->fresh(), 'manual', $staff);
        $secondInvoice = Invoice::query()->findOrFail($second->invoice_id);

        $this->assertSame('100.00', (string) $firstInvoice->fresh()->total);
        $this->assertSame('250.00', (string) $secondInvoice->total);
        $this->assertSame('2026-04', $second->period_key);
        $this->assertNotSame($firstInvoice->id, $secondInvoice->id);

        Carbon::setTestNow();
    }

    public function test_end_date_stops_generation(): void
    {
        $staff = $this->createStaffUser();
        $yesterday = now()->subDay()->toDateString();
        $schedule = RecurringBillingSchedule::factory()->create([
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => $yesterday,
            'next_generation_date' => now()->toDateString(),
        ]);

        $result = app(RecurringBillingService::class)->generateForSchedule($schedule, 'scheduler', $staff);
        $this->assertSame(RecurringBillingGenerationStatus::Skipped, $result->status);
        $this->assertNull($schedule->fresh()->next_generation_date);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_scheduler_command_processes_due_schedules(): void
    {
        RecurringBillingSchedule::factory()->create([
            'next_generation_date' => now()->toDateString(),
        ]);

        Artisan::call('recurring-billing:process-due', ['--sync' => true]);

        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(
            RecurringBillingGenerationStatus::Succeeded,
            RecurringBillingGeneration::query()->firstOrFail()->status,
        );
    }

    public function test_unauthorized_cannot_generate(): void
    {
        $this->seedRolesAndPermissions();
        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::findOrCreate('RbViewer', 'web');
        $role->syncPermissions([Permissions::RECURRING_BILLING_VIEW]);
        $user->assignRole($role);

        $schedule = RecurringBillingSchedule::factory()->create([
            'next_generation_date' => now()->toDateString(),
        ]);

        $this->actingAs($user)
            ->post(route('recurring-billing.generate', $schedule))
            ->assertForbidden();
    }

    public function test_month_end_calendar_clamping(): void
    {
        $from = Carbon::parse('2026-01-31');
        $next = RecurringBillingCalendar::advance($from, RecurringBillingFrequency::Monthly);
        $this->assertSame('2026-02-28', $next->toDateString());

        $leap = RecurringBillingCalendar::advance(Carbon::parse('2024-01-31'), RecurringBillingFrequency::Monthly);
        $this->assertSame('2024-02-29', $leap->toDateString());
    }

    public function test_resume_skips_catch_up_backlog(): void
    {
        $staff = $this->createStaffUser();
        $schedule = RecurringBillingSchedule::factory()->paused()->create([
            'start_date' => now()->subMonths(3)->day(15)->toDateString(),
            'next_generation_date' => now()->subMonths(2)->toDateString(),
        ]);

        $this->actingAs($staff)->post(route('recurring-billing.resume', $schedule))->assertRedirect();
        $schedule->refresh();

        $this->assertSame(RecurringBillingStatus::Active, $schedule->status);
        $this->assertTrue(
            $schedule->next_generation_date->gte(now()->startOfDay()),
            'Resume must not leave next_generation_date in the past (no backlog).',
        );
        $this->assertSame(0, Invoice::query()->count());
    }
}
