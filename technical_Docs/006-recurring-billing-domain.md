# ADMAN Technical Documentation — Recurring Billing Domain

## Purpose

Automate creation of **ordinary independent invoices** from a schedule.

```text
Customer → Recurring Billing Schedule → Billing Period → Issued Invoice
         → Existing Invoice Lifecycle → Payments
```

Recurring Billing is **not** a second invoice or payment system.

## Schedule model

Table: `recurring_billing_schedules`

| Field | Notes |
| --- | --- |
| `business_id` | Singleton business |
| `contact_id` | Must be Customer lifecycle |
| `frequency` | `monthly` (primary), `quarterly`, `yearly` |
| `start_date` / `end_date` | Business-local dates |
| `next_generation_date` | Next due generation; null when finished/cancelled |
| `status` | `active` \| `paused` \| `cancelled` |
| `payment_term_days` | Applied to generated invoice due dates |
| discount / tax fields | Future billing configuration |
| items | `recurring_billing_items` |

## Lifecycle

- **Active** — eligible for generation when `next_generation_date ≤ business today`
- **Paused** — no generation; config retained
- **Cancelled** — no generation; `next_generation_date` cleared; history kept

### Resume (no catch-up)

Missed periods are **not** backfilled. `next_generation_date` is set to the next occurrence on or after business-today, preserving the preferred day-of-month from `start_date` when possible.

## Frequencies & calendar

Business timezone (Task 001) is used for “today”.

Month-end rule: `addMonthsNoOverflow` / day clamp (e.g. 31 Jan → 28/29 Feb).

| Frequency | `period_key` example |
| --- | --- |
| Monthly | `2026-09` |
| Quarterly | `2026-Q3` |
| Yearly | `2026` |

## Invoice generation

1. Claim period row (`unique(schedule_id, period_key)`) with row locks
2. Create+issue invoice via `InvoiceService::createAndIssue`
3. Mark generation succeeded; link `invoice_id`
4. Advance `next_generation_date`
5. Audit

Generated invoices are **Issued**, unpaid, with normal snapshots/numbering. They participate fully in Payments and Documents.

Schedule config changes affect **future** periods only.

## Idempotency & concurrency

- DB unique on `(schedule_id, period_key)`
- `lockForUpdate` on schedule + generation
- Duplicate job/manual/retry returns existing succeeded generation
- Invoice creation failure marks generation `failed` without leaving orphan invoices (transaction)

## Execution history

Table: `recurring_billing_generations`

Statuses: `pending` \| `processing` \| `succeeded` \| `failed` \| `skipped`

## Scheduler

```text
Schedule daily 01:00 → recurring-billing:process-due
  → ProcessDueRecurringBillingSchedules job
  → RecurringBillingService::processDueSchedules
```

Manual: `php artisan recurring-billing:process-due --sync`

Staff UI: Generate / Retry failed (same service path).

## Permissions

`recurring_billing.view|create|update|pause|resume|cancel|generate`

## Audit

`recurring_billing.created|updated|paused|resumed|cancelled|invoice_generated|generation_failed`

## Deferred

- Catch-up billing for missed periods
- Communication delivery of generated invoices
- Reminders / AI
- External subscription providers
- Proration / usage billing

## Key modules

- `app/Services/RecurringBillingService.php`
- `app/Support/RecurringBillingCalendar.php`
- `app/Jobs/ProcessDueRecurringBillingSchedules.php`
- `app/Console/Commands/ProcessRecurringBillingCommand.php`
- `routes/recurring-billing.php`
- `resources/js/pages/recurring-billing/*`
- `tests/Feature/RecurringBillingTest.php`
