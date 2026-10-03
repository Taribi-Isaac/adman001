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
| `delivery_channel` | `none` (default) \| `email` \| `whatsapp` \| `both` — see Invoice delivery |
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

### Amount rule

A schedule's computed total (after discount and tax) must be **greater than zero**; create and update reject it otherwise (error on `items`). Individual zero-priced lines stay allowed, matching invoices and quotes (`unit_price min:0`). Schedules created before this rule (e.g. one producing a ₦0 invoice) are not changed; the rule applies the next time they are saved.

## Invoice delivery

Configured per schedule (`delivery_channel`). Existing schedules were migrated to `none`, so nothing starts sending without staff choosing a channel.

```text
generation succeeded (transaction committed)
  → DeliverRecurringInvoice job (after commit, Horizon)
  → RecurringInvoiceDeliveryService::process
      1. invoice PDF via DocumentService (once per generation, all channels incl. none)
      2. per channel: existing EmailOutboundService::queueInvoiceEmail /
         WhatsAppOutboundService::queueInvoiceWhatsApp (actor type: system)
```

- Runs without a logged-in user; the acting user is the first Super Administrator (same rule as reminders).
- Email: existing invoice template, PDF attached, Resend, conversation history.
- WhatsApp: all existing rules — opt-in, valid number, 24-hour window (PDF document inside, approved `invoice` template outside). No template configured outside the window → not delivered and recorded; no free-form fallback.
- Create/update validate the channel: email needs a valid customer email; WhatsApp needs opt-in and a valid number. The send path re-checks at delivery time.
- The generation snapshots `delivery_channel`; later schedule edits don't change past deliveries.

### Failure isolation & idempotency

- Delivery never touches the invoice or the generation status. The invoice stays issued whatever happens.
- PDF failure → `recurring_billing_generations.pdf_failure_reason`; after the job's 3 attempts the pending channels are marked `not_deliverable`.
- Table `recurring_billing_deliveries`: one row per `(generation_id, channel)` (unique), claimed under a row lock. Statuses: `pending` → `queued` (linked `message_id`) or `not_deliverable` (`failure_reason`). Provider outcome (sent / delivered / failed) is the linked Message status.
- Channels are independent: one failing doesn't stop the other.
- Re-running the job, the scheduler or a retry never queues a channel twice and never creates a second PDF.
- Staff resend manually from the invoice page (existing Send buttons) if a channel was not delivered.

Known limitation (existing document behaviour): each send creates a new secure view link for the invoice PDF, so with `both` the email's "view online" link is replaced once WhatsApp queues. The PDF itself is attached to both.

## Idempotency & concurrency

- DB unique on `(schedule_id, period_key)`
- `lockForUpdate` on schedule + generation
- Duplicate job/manual/retry returns existing succeeded generation
- Invoice creation failure marks generation `failed` without leaving orphan invoices (transaction)

## Execution history

Table: `recurring_billing_generations`

Statuses: `pending` \| `processing` \| `succeeded` \| `failed` \| `skipped`

Delivery columns: `delivery_channel` (snapshot), `document_id` (invoice PDF), `pdf_failure_reason`; per-channel rows in `recurring_billing_deliveries`. The schedule page shows PDF link and per-channel status.

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

`recurring_billing.created|updated|paused|resumed|cancelled|invoice_generated|generation_failed|invoice_pdf_generated|delivery_queued|delivery_failed`

## Deferred

- Catch-up billing for missed periods
- Reminders / AI
- External subscription providers
- Proration / usage billing

## Key modules

- `app/Services/RecurringBillingService.php`
- `app/Services/RecurringInvoiceDeliveryService.php`
- `app/Jobs/DeliverRecurringInvoice.php`
- `app/Support/RecurringBillingCalendar.php`
- `app/Jobs/ProcessDueRecurringBillingSchedules.php`
- `app/Console/Commands/ProcessRecurringBillingCommand.php`
- `routes/recurring-billing.php`
- `resources/js/pages/recurring-billing/*`
- `tests/Feature/RecurringBillingTest.php`
- `tests/Feature/RecurringInvoiceDeliveryTest.php`
