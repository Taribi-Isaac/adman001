<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceLifecycleStatus;
use App\Enums\InvoicePaymentStatus;
use App\Enums\PaymentClaimStatus;
use App\Enums\QuoteStatus;
use App\Enums\ReminderOccurrenceStatus;
use App\Models\AuditEvent;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\PaymentClaim;
use App\Models\Quote;
use App\Models\ReminderOccurrence;
use App\Support\DocumentSnapshots;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $business = Business::current();
        $today = DocumentSnapshots::businessToday($business)->toDateString();

        $outstandingInvoices = Invoice::query()
            ->where('lifecycle_status', InvoiceLifecycleStatus::Issued)
            ->whereIn('payment_status', [
                InvoicePaymentStatus::Unpaid->value,
                InvoicePaymentStatus::PartiallyPaid->value,
            ])
            ->where('balance_due', '>', 0)
            ->count();

        $overdueInvoices = Invoice::query()
            ->where('lifecycle_status', InvoiceLifecycleStatus::Issued)
            ->whereIn('payment_status', [
                InvoicePaymentStatus::Unpaid->value,
                InvoicePaymentStatus::PartiallyPaid->value,
            ])
            ->where('balance_due', '>', 0)
            ->whereDate('due_date', '<', $today)
            ->count();

        $pendingPaymentClaims = PaymentClaim::query()
            ->whereIn('status', [
                PaymentClaimStatus::PendingVerification->value,
                PaymentClaimStatus::AwaitingInformation->value,
            ])
            ->count();

        $quotesAwaitingResponse = Quote::query()
            ->where('status', QuoteStatus::Issued)
            ->count();

        $remindersNeedingAttention = ReminderOccurrence::query()
            ->whereIn('status', [
                ReminderOccurrenceStatus::Pending->value,
                ReminderOccurrenceStatus::Failed->value,
            ])
            ->count();

        $recentActivity = AuditEvent::query()
            ->with('actor:id,name')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (AuditEvent $event) => [
                'id' => $event->id,
                'event' => $event->event,
                'description' => $event->description,
                'actor_name' => $event->actor?->name,
                'created_at' => $event->created_at?->timezone($business->timezone)->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Dashboard', [
            'greeting' => [
                'user_name' => $user?->name ?? 'there',
                'business_name' => $business->name,
            ],
            'metrics' => [
                [
                    'key' => 'outstanding_invoices',
                    'label' => 'Outstanding invoices',
                    'count' => $outstandingInvoices,
                    'hint' => 'Issued invoices with a remaining balance',
                    'href' => route('invoices.index', [
                        'lifecycle' => InvoiceLifecycleStatus::Issued->value,
                        'payment' => InvoicePaymentStatus::Unpaid->value,
                    ], false),
                    'empty' => 'No outstanding invoices',
                    'visible' => $user?->can(Permissions::INVOICES_VIEW) ?? false,
                ],
                [
                    'key' => 'overdue_invoices',
                    'label' => 'Overdue invoices',
                    'count' => $overdueInvoices,
                    'hint' => 'Past due date with an outstanding balance',
                    'href' => route('invoices.index', [
                        'lifecycle' => InvoiceLifecycleStatus::Issued->value,
                        'due' => 'overdue',
                    ], false),
                    'empty' => 'No overdue invoices',
                    'visible' => $user?->can(Permissions::INVOICES_VIEW) ?? false,
                ],
                [
                    'key' => 'pending_payment_claims',
                    'label' => 'Pending payment verification',
                    'count' => $pendingPaymentClaims,
                    'hint' => 'Claims awaiting staff review (not confirmed payments)',
                    'href' => route('payment-claims.index', [
                        'status' => PaymentClaimStatus::PendingVerification->value,
                    ], false),
                    'empty' => 'No payment claims waiting',
                    'visible' => $user?->can(Permissions::PAYMENTS_CLAIMS_VIEW) ?? false,
                ],
                [
                    'key' => 'quotes_awaiting_response',
                    'label' => 'Quotes awaiting response',
                    'count' => $quotesAwaitingResponse,
                    'hint' => 'Issued quotes still waiting on the customer',
                    'href' => route('quotes.index', [
                        'status' => QuoteStatus::Issued->value,
                    ], false),
                    'empty' => 'No quotes awaiting response',
                    'visible' => $user?->can(Permissions::QUOTES_VIEW) ?? false,
                ],
                [
                    'key' => 'reminders_attention',
                    'label' => 'Reminders needing attention',
                    'count' => $remindersNeedingAttention,
                    'hint' => 'Pending or failed invoice reminder occurrences',
                    'href' => route('settings.automation.reminders.edit', absolute: false),
                    'empty' => 'No reminder issues',
                    'visible' => $user?->can(Permissions::AUTOMATION_REMINDERS_VIEW) ?? false,
                ],
            ],
            'recent_activity' => [
                'items' => $recentActivity,
                'visible' => $user?->can(Permissions::AUDIT_VIEW) ?? false,
                'href' => route('settings.audit.index', absolute: false),
            ],
        ]);
    }
}
