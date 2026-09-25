# 007 — Email Delivery

## Purpose

Outbound email is ADMAN’s first external communication delivery channel. It plugs into the existing Communication, Document, and Business domains so staff can send quotes, invoices, and payment acknowledgements without creating a second messaging or document system.

**Flow:** Business action → Outbound `Message` (pending) → Queue job → Email delivery adapter → Laravel Mail / provider → Message status update.

Recurring invoice generation does **not** automatically send email.

---

## Architecture

```text
EmailOutboundService
      ↓
Conversation + Message (Communication domain)
      ↓
SendOutboundEmailJob (queue)
      ↓
EmailDeliveryAdapter (contract)
      ↓
LaravelMailEmailDeliveryAdapter
      ↓
Laravel Mail (MAIL_MAILER)
      ↓
Resend (production) / log|array (local/tests)
```

Provider-specific API calls stay behind `App\Contracts\EmailDeliveryAdapter`. The adapter uses Laravel’s mail abstraction so **Resend**, SMTP, `log`, or `array` can be configured via environment without changing domain code.

**Production provider:** Resend (not Amazon SES). Domain: `raslordeckltd.com` (verified in Resend).

---

## Provider abstraction

| Component | Role |
|-----------|------|
| `EmailDeliveryAdapter` | `send(EmailDeliveryPayload): EmailDeliveryResult` |
| `LaravelMailEmailDeliveryAdapter` | Bound in `AppServiceProvider` |
| `DocumentOutboundMail` | Markdown templates for MVP document types |

To add a direct provider SDK later, implement the contract and rebind the interface.

---

## Configuration

### Environment (secrets / transport)

Production (Resend API mailer):

| Variable | Purpose |
|----------|---------|
| `MAIL_MAILER=resend` | Use Laravel Resend transport |
| `RESEND_API_KEY` | Resend API key (server `.env` only) |
| `MAIL_FROM_ADDRESS` | Fallback From if `Business.email` empty (use `@raslordeckltd.com`) |
| `MAIL_FROM_NAME` | Fallback From name |
| `ADMAN_EMAIL_ENABLED` | Deployment kill switch (`config/adman.php`) |

Local/tests typically use `MAIL_MAILER=log` or `array`. SMTP vars remain available for non-production alternatives.

Package: `resend/resend-php` (Laravel native Resend transport). Config: `config/mail.php` mailer `resend`, `config/services.php` → `RESEND_API_KEY`.

**Never store provider API keys in the database, Git, or `.env.example` values.**

### Delivery-status semantics

- ADMAN `sent` = Laravel/Resend **accepted** the submission (Symfony Message-ID when available; otherwise a local `laravel-mail-{id}` fallback).
- Provider dashboard events (e.g. Resend `delivered` / bounce / complaint) are **not** written back into ADMAN until webhooks are implemented. Do not treat UI “Sent” as durable mailbox confirmation inside the app.
- Task 023 controlled production send: Resend accepted the API request and later reported `last_event=delivered` for the authorized test — ADMAN message remained `sent` (correct for current semantics).

### Business settings (org)

- `Business.email` — preferred From when valid
- `Business.email_reply_to` — optional Reply-To
- `Business.outbound_email_enabled` — org-level enable/disable

---

## Identity mapping

- Channel: `email`
- `external_id`: normalized lowercase email address
- Linked to the Customer `Contact` when sending documents
- `ConversationService::findOrCreateIdentity` reuses existing identities

Duplicate identities for the same normalized address are avoided.

---

## Message lifecycle

| Status | Meaning |
|--------|---------|
| `recorded` | Internal history only (compose) — not sent externally |
| `pending` | Queued for delivery |
| `processing` | Worker claimed the send attempt |
| `sent` | Accepted by the mail transport / provider submission succeeded |
| `delivered` | Reserved for future provider webhooks (not claimed from Laravel Mail alone) |
| `failed` | Visible failure; retryable |

UI copy: **Queued for delivery** / **Sent** — not “Delivered” unless a provider confirms delivery.

Extra message fields: `subject`, `template_key`, `document_id`, `failure_reason`, `sent_at`, `failed_at`, `meta` (includes `to`, `secure_url`).

---

## Queue behaviour

- Job: `SendOutboundEmailJob` (`ShouldBeUnique` per message id, 3 tries, backoff)
- Existing Redis / Horizon infrastructure
- Sync in tests (`QUEUE_CONNECTION=sync`)

---

## Idempotency

Guarantee: **at-least-once internal processing + controlled send attempts**.

- One intentional staff “Send” creates one `Message` and one job
- Job / `deliverQueuedMessage` uses `lockForUpdate`; terminal `sent`/`delivered` skips re-send
- Staff **retry** reuses the same message row (does not create a duplicate conversation message)
- A second intentional Send creates a new message (deliberate resend)

Exactly-once external delivery cannot be guaranteed by providers; duplicates are possible only if the provider accepted mail before ADMAN recorded success (documented limitation).

---

## Templates

Markdown mail views under `resources/views/mail/documents/`:

- Quote
- Invoice
- Payment acknowledgement

Include business name, customer name, document number, amount/due date where relevant, secure document CTA, and plain-text fallback via Laravel markdown mail.

Not a drag-and-drop designer.

---

## Document sending

Authorized staff (`messages.send`) may **Send by Email** from:

- Quote show
- Invoice show
- Payment show (confirmed acknowledgement)

Behaviour:

1. Reject drafts / cancelled invoices / unconfirmed payments
2. Require Customer contact with valid email
3. Use latest matching PDF or generate one
4. Attach the PDF from private storage (`application/pdf`, document filename)
5. Create/regenerate secure link (plain token for optional browser CTA; hash stored)
6. Open/reuse email conversation
7. Create pending outbound message
8. Dispatch job
9. Flash: queued for delivery

Secure links remain available for browser viewing; they are not a substitute for the PDF attachment.

---

## Secure links

Reuses `DocumentService::createSecureLink` and public `GET /d/{token}`. Expiry and revocation unchanged. Emailing does not create a customer portal.

---

## Conversation integration

Document emails appear in Communication history on the contact’s email conversation. Channel badge and delivery status are shown; retry is available when permitted.

---

## Permissions

| Permission | Purpose |
|------------|---------|
| `messages.send` | Queue document emails |
| `messages.retry` | Retry failed/pending email messages |

Existing `messages.compose` remains internal-only (no external send).

Staff role receives both new permissions via `RolesAndPermissionsSeeder`.

---

## Audit

Meaningful events only:

- `email.queued`
- `email.sent`
- `email.failed`
- `email.retry_queued`

Detailed delivery state lives on the message record.

---

## Failure handling

Recorded failures include a staff-safe reason (no raw credentials). Retry uses the same generation path. Configuration / missing recipient / provider rejection are validation or failed-message outcomes.

---

## Known limitations

- No inbound email / mailbox sync
- No Resend delivery/bounce/complaint webhooks yet (`delivered` status reserved)
- Automated invoice reminders are owned by Task 009 (`ReminderService` → `queueInvoiceReminderEmail`); this domain still owns delivery only
- No automatic email on recurring generation
- No WhatsApp
- No bulk/marketing mail
- Amazon SES is **not** used (Resend is the production provider)

---

## Deferred functionality

- Inbound email + threading
- Resend delivery webhooks / bounce handling
- Automated reminders (owned by reminder domain)
- WhatsApp delivery adapter
- Richer per-contact communication preferences

---

## Practical setup (production Resend)

**Status (Task 023):** Production Resend is active. Controlled invoice PDF email verified through Horizon → Resend → authorized recipient.

1. Confirm domain `raslordeckltd.com` is verified in Resend
2. Create a Resend API key; set `RESEND_API_KEY` in server `.env` only (never Git / `.env.example` values / docs)
3. Set `MAIL_MAILER=resend`, `MAIL_FROM_ADDRESS=no-reply@raslordeckltd.com` (or rely on `Business.email` under the same domain)
4. Set Business `email` / `email_reply_to` / `outbound_email_enabled` in Settings
5. Set `ADMAN_EMAIL_ENABLED=true`
6. `umask 002 && php artisan config:cache` then ensure `bootstrap/cache/config.php` is `adman:www-data` mode `664` (PHP-FPM cannot read mode `600`) and `sudo systemctl restart adman-horizon`
7. Send from an issued quote/invoice or confirmed payment acknowledgement to an **authorized test recipient**
8. Confirm Horizon processes `SendOutboundEmailJob`, Resend shows the message, recipient receives PDF attachment

Horizon production workers remain **1** (`maxProcesses=1`). Do not raise concurrency without measured sustained memory pressure.

### Diagnose failed email jobs

```bash
php artisan horizon:status
journalctl -u adman-horizon -n 100 --no-pager
# Inspect failed jobs in Horizon UI (/horizon — requires system.horizon)
# Confirm RESEND_API_KEY is set (do not print it) and MAIL_MAILER=resend
# Confirm ADMAN_EMAIL_ENABLED=true and Business.outbound_email_enabled
```