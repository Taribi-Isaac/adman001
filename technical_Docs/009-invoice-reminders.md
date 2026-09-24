# 009 — Invoice Reminder Automation

## Purpose

ADMAN invoice reminders are a **specialized invoice-reminder domain**, not a generic workflow engine.

```text
Invoice → Reminder Rule → Eligibility → Reminder Occurrence → Email/WhatsApp → Message → Delivery History
```

**Reminder Automation** decides *when* and *why* a reminder is appropriate.  
**Communication** (Tasks 007–008) decides *how* it is delivered.

Recurring Billing (Task 006) only generates invoices. After issue, those invoices are ordinary invoices and participate in reminders like any other.

---

## Architecture

```text
Laravel Scheduler (daily 07:00 business day)
        ↓
reminders:process-due
        ↓
ProcessDueInvoiceReminders (queue)
        ↓
ReminderService::processDue
  - enabled rules
  - invoices whose due_date + offset = business today
  - claim occurrence (unique invoice+rule+channel)
        ↓
ProcessInvoiceReminderOccurrence (unique job)
        ↓
ReminderService::processOccurrence
  - lock + eligibility recheck
  - EmailOutboundService / WhatsAppOutboundService
        ↓
existing Message + queue + provider
```

Core service: `App\Services\ReminderService`.

---

## Default rules

Business-wide defaults (seeded / ensured):

| Offset | Meaning |
|--------|---------|
| `-7` | 7 days before due date |
| `-2` | 2 days before due date |
| `+1` | 1 day after due date |

Example: due date 20 October → reminders on 13 Oct, 18 Oct, 21 Oct (business timezone calendar dates).

Administrators may change offsets without code changes under **Settings → Automation → Invoice Reminders**.

Bounds: integer offsets from **-90** to **+90**. Duplicate offsets rejected. Rules keep stable IDs for idempotency.

Not in MVP:

- per-customer schedules
- per-invoice overrides
- per-recurring-schedule overrides
- condition/action workflow builders

---

## Business timezone

“Today” is `CarbonImmutable::now($business->timezone)->startOfDay()` (Task 001 business timezone).

Do not use the server timezone for eligibility.

---

## Data model

### `reminder_rules`

- `business_id`, `offset_days`, `is_enabled`, `sort_order`, timestamps
- Unique `(business_id, offset_days)`

### `reminder_occurrences`

One row per **invoice + rule + channel**:

- `invoice_id`, `reminder_rule_id`, `channel`, `occurrence_date`
- `status`, `message_id`, `failure_reason`, `skip_reason`, `queued_at`, `completed_at`
- Unique `(invoice_id, reminder_rule_id, channel)` — concurrency-safe idempotency

### Business / contact

- `businesses.invoice_reminders_enabled` — global kill switch
- `contacts.reminder_channel` — `email` | `whatsapp` | `both` (default `email`)

---

## Eligibility

A reminder may be claimed/sent only when:

- feature enabled and rule enabled
- invoice is **Issued** (not Draft / Cancelled)
- invoice has a due date
- payment status is outstanding (`unpaid` or `partially_paid`)
- `balance_due > 0`

### Partial payment

Partially paid invoices remain eligible. Templates use **current `balance_due`**, not the original total. Calculations come from the existing Invoice/Payment domain — reminders do not recompute balances.

### Payment claims

Pending / awaiting-information / rejected claims do **not** stop reminders. Only confirmed payments that clear outstanding balance affect eligibility.

### State recheck

Eligibility is checked again inside `processOccurrence` under row locks. If the customer pays between claim and worker execution, the occurrence is **Skipped**.

---

## Channel selection

Contact `reminder_channel`:

| Preference | Occurrences |
|------------|-------------|
| Email | Email only |
| WhatsApp | WhatsApp only |
| Both | Independent Email + WhatsApp occurrences for the same rule |

Missing channel credentials → occurrence **Not deliverable** (not silent drop).

### Email

Requires valid customer email, `adman.email.enabled`, `outbound_email_enabled`, and `EmailOutboundService::queueInvoiceReminderEmail`.

### WhatsApp

Requires opt-in, valid WhatsApp identity, channel enabled, configured Meta template (`WHATSAPP_TEMPLATE_INVOICE_REMINDER`), and `WhatsAppOutboundService::queueInvoiceReminderWhatsApp`.

Opt-in and provider rules are not bypassed.

---

## Message content

### Email (`mail.documents.invoice-reminder`)

Before due: friendly upcoming-due wording + customer, invoice number, due date, **outstanding balance**, secure link, business identity.  
After due: same facts with clear **overdue** wording (only when invoice due state is overdue). No threats, no payment-failure claims.

### WhatsApp

Uses approved template key `invoice_reminder`. Body parameters remain three Meta slots (name / invoice context / secure URL). Reminder slot 2 includes outstanding + due date for factual context; the linked invoice PDF remains the source of truth for amounts. Ops must keep Meta template copy aligned.

---

## Idempotency and concurrency

Invariant: **one invoice + one rule + one channel ≤ one occurrence**.

Protections:

1. Unique DB constraint on occurrences
2. Transaction + `lockForUpdate` when claiming
3. Catch `UniqueConstraintViolationException`
4. `ProcessInvoiceReminderOccurrence` implements `ShouldBeUnique`
5. Scheduler uses `withoutOverlapping()`

Successful channel delivery is never resent merely because the other channel failed.

---

## Occurrence statuses

| Status | Meaning |
|--------|---------|
| Pending | Claimed; awaiting worker |
| Queued | Handed to Email/WhatsApp Message queue |
| Sent / Failed | Reserved for future sync with Message if needed; delivery detail lives on `Message` |
| Skipped | Ineligible at send time (e.g. paid) |
| Not deliverable | Channel/contact cannot receive |

Provider retries use existing email/WhatsApp retry paths on `Message`. Reminder occurrence is not corrupted by transient provider failures after queueing.

---

## Scheduler / commands

```bash
php artisan reminders:process-due
php artisan reminders:process-due --sync
```

Scheduled daily at **07:00** (`routes/console.php`).

---

## Settings UI

**Settings → Automation → Invoice Reminders**

- Enable/disable globally
- View/edit rule offsets and enabled flags
- Permission-gated

**Invoice detail → Reminders**: rules + occurrence history with channel, date, status, message status, skip/failure reasons.

**Contact edit**: reminder channel preference.

---

## Permissions

- `automation.reminders.view`
- `automation.reminders.manage`

Server-authorized; frontend hiding is not sufficient.

---

## Audit

`AuditLogger` events include:

- `reminders.enabled` / `reminders.disabled`
- `reminder_rule.created` / `updated` / `disabled`
- `reminder.queued` / `reminder.skipped` / `reminder.failed`

No audit of scheduler heartbeats.

---

## Security

- Authenticated admin configuration only
- No public reminder APIs
- Secure document links reused (Task 004)
- No provider credentials in reminder domain
- Validated offsets; concurrency-safe occurrence creation

---

## Operational requirements

- Queue workers must run for async processing
- Business timezone must be correct
- Email/WhatsApp channels configured independently
- Meta must approve `adman_invoice_reminder` (or configured name)

---

## Limitations / deferred

- No “Send reminder now” button in MVP (would reuse same service if added later)
- No per-customer/invoice/schedule rule overrides
- No escalation chains / workflow canvas / AI
- Occurrence `sent`/`failed` not continuously mirrored from Message (UI shows message status)
- Dashboard attention for failed reminders is minimal / deferred polish
- WhatsApp Meta template copy must match the three-parameter payload shape

---

## Decisions recorded

**Customer channel preference:** Contact-level `reminder_channel` (`email` | `whatsapp` | `both`), default email. No complex fallback hierarchy.

**Both channels:** Separate occurrences; independent delivery state.

If product later needs different fallback or opt-out semantics beyond channel preference + WhatsApp opt-in, escalate as **DECISION REQUIRED**.
