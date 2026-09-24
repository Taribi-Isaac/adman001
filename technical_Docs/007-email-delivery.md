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
Laravel Mail (MAIL_* / configured mailer)
```

Provider-specific API calls stay behind `App\Contracts\EmailDeliveryAdapter`. The first adapter uses Laravel’s mail abstraction so SMTP, SES, Postmark, Resend, `log`, or `array` can be configured via environment without changing domain code.

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

- `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, …
- `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` (fallback From)
- `ADMAN_EMAIL_ENABLED` — deployment kill switch (`config/adman.php`)

**Never store provider API keys or SMTP passwords in the database.**

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
- No delivery/open webhooks for `delivered` from Laravel Mail alone
- Automated invoice reminders are owned by Task 009 (`ReminderService` → `queueInvoiceReminderEmail`); this domain still owns delivery only
- No automatic email on recurring generation
- No WhatsApp
- No bulk/marketing mail

---

## Deferred functionality

- Inbound email + threading
- Provider delivery webhooks
- Automated reminders (future task)
- WhatsApp delivery adapter
- Richer per-contact communication preferences / bounce handling

---

## Practical setup

1. Configure `MAIL_*` for the chosen Laravel mailer
2. Set Business email / reply-to / outbound enabled
3. `php artisan migrate` + reseed permissions if needed
4. Run queue workers / Horizon
5. Send from an issued quote/invoice or confirmed payment acknowledgement
