# ADMAN Technical Documentation — Contacts Domain

## Purpose

The Contacts domain is the first business domain after the application foundation.

It provides a canonical identity record that future Communication, Quotes, Invoices, Payments, Recurring Billing and AI features can reference.

## Terminology

| Term | Meaning |
| --- | --- |
| **Contact** | A known person/organization/communication identity |
| **Unknown** | Contact exists with limited information; not yet a prospect/customer |
| **Prospect** | Contact identified as a potential customer |
| **Customer** | Established commercial relationship |

Navigation label remains **Customers** (approved UX). Backend/domain naming uses **Contact**.

## Lifecycle

```text
Unknown → Prospect → Customer
Unknown → Customer   (explicit staff action only)
```

Rules:

- Transitions are deliberate and permission-protected.
- Receiving a message later must **not** auto-promote a contact.
- No automatic downgrade path.
- Archive/deactivate preserves the record for future historical references.
- Soft archive uses `archived_at`; records are never hard-deleted by this domain.

## Data model

Table: `contacts`

| Column | Notes |
| --- | --- |
| `type` | `individual` \| `organization` |
| `status` | `unknown` \| `prospect` \| `customer` |
| `display_name` | Derived from name/organization/identifiers |
| `first_name`, `last_name` | Optional person fields |
| `organization_name` | Optional organization field |
| `email`, `phone`, `whatsapp_id` | Communication identifiers (nullable) |
| `whatsapp_opt_in` | Boolean (default false). Transactional WhatsApp gate — see Consent below |
| `reminder_channel` | `email` \| `whatsapp` \| `both` (Task 009) |
| consent columns | Nullable `*_at` timestamps + `*_source` (Task 035) — see Consent below |
| address fields | Optional |
| `notes` | Optional internal notes |
| `archived_at` | Null when active |

Indexed for status, type, display_name, email, phone, whatsapp_id, archived_at.

## Consent (Task 035)

Consent used by broadcasts (Task 038, see `017-broadcasts.md`). Transactional sends only use `whatsapp_opt_in`.

| Flag (form field) | Stored as | Meaning |
| --- | --- | --- |
| `whatsapp_opt_in` | `whatsapp_opt_in` (bool) + `whatsapp_opt_in_at` / `whatsapp_opt_in_source` | Customer agreed to business WhatsApp messages. Unchanged meaning: still the gate for WhatsApp document sends and WhatsApp reminders. Not sufficient for broadcasts |
| `whatsapp_broadcast_opt_in` (Task 038) | `whatsapp_broadcast_opt_in_at` / `_source` | Customer explicitly agreed to WhatsApp broadcasts (marketing). Required for WhatsApp broadcasts; never read by transactional sends |
| `whatsapp_broadcast_opt_out` | `whatsapp_broadcast_opt_out_at` / `_source` | Customer does not want WhatsApp broadcasts. Broadcast-only; never blocks transactional sends |
| `email_broadcast_opt_in` | `email_broadcast_opt_in_at` / `_source` | Explicit opt-in to email broadcasts |
| `email_broadcast_unsubscribed` | `email_broadcast_unsubscribed_at` / `email_broadcast_unsubscribe_source` | Unsubscribed from email broadcasts. Broadcast-only; never blocks invoice/quote/payment/reminder emails |

Sources (`App\Enums\ConsentSource`): `in_person`, `website`, `whatsapp`, `phone`, `staff_recorded`, `other`, plus `unsubscribe_link` (system-only: set when a recipient uses the email unsubscribe link; staff cannot choose it, `ConsentSource::staffSelectable()`). A source is evidence of how consent was recorded, **not** proof of legal consent.

Rules (`ContactService::applyConsent`, definitions in `App\Support\ContactConsent`):

- Turning a flag on requires a source (validation error on the `*_source` field) and stamps `*_at = now()`.
- Turning a flag off clears `*_at` and `*_source`; the previous values remain in the audit trail (no separate history table).
- Saving an already-on flag without a source changes nothing. Supplying a new source updates it; for a legacy `whatsapp_opt_in = true` row with no timestamp, supplying a source stamps `whatsapp_opt_in_at` (evidence recorded now).
- Permission: `contacts.update`, enforced server-side. Creating a contact with any consent flag on also requires `contacts.update` (403 otherwise).

Existing data: the migrations add nullable columns only (Task 035, and `2026_10_02_100000_add_whatsapp_broadcast_opt_in_to_contacts` in Task 038). No contact is opted in, `whatsapp_opt_in` values are unchanged, and `down()` drops the columns.

### Broadcast eligibility

`App\Services\BroadcastEligibilityService` returns a `BroadcastEligibilityResult` (all failing `BroadcastIneligibilityReason`s, not just the first). Audience statuses are a parameter (default `[Customer]`; `Unknown` is rejected).

- **WhatsApp** (`forWhatsApp`): not archived; status in audience; `adman.whatsapp.enabled` + `outbound_whatsapp_enabled`; a normalizable WhatsApp number (`whatsapp_id`, else `phone`); any existing WhatsApp `CommunicationIdentity` for that number is active and not linked to another contact; `whatsapp_opt_in` true **with** `whatsapp_opt_in_at` recorded; `whatsapp_broadcast_opt_in_at` recorded (Task 038; reason `no_whatsapp_broadcast_opt_in`); no broadcast opt-out.
- **Email** (`forEmail`): not archived; status in audience; `adman.email.enabled` + `outbound_email_enabled`; valid email; `email_broadcast_opt_in_at` set; not unsubscribed.

Legacy `whatsapp_opt_in = true` contacts without a recorded timestamp are **not** broadcast-eligible (`whatsapp_opt_in_not_recorded`) until staff record a source. They remain valid for transactional WhatsApp.

Transactional services (`EmailOutboundService`, `WhatsAppOutboundService`, `ReminderService`) do not consult this service or the broadcast columns.

## Permissions

| Permission | Purpose |
| --- | --- |
| `contacts.view` | List/show |
| `contacts.create` | Create |
| `contacts.update` | Edit (not lifecycle) |
| `contacts.promote` | Lifecycle promotion |
| `contacts.archive` | Archive / restore |

Seeded onto:

- **Super Administrator** — all permissions
- **Staff** — all Contacts permissions (operational default)

## Duplicate handling

Among **active** (non-archived) contacts:

- Matching `email`, `phone`, or `whatsapp_id` is rejected with a validation error naming the existing contact.
- No silent merge.
- Archived contacts do not block reuse of identifiers.
- Restoring an archived contact fails if an active contact already uses the same identifier.

## Key classes

- `App\Models\Contact`
- `App\Enums\ContactType`, `App\Enums\ContactStatus`
- `App\Services\ContactService`
- `App\Enums\ConsentSource`, `App\Support\ContactConsent`
- `App\Services\BroadcastEligibilityService`, `App\Support\BroadcastEligibilityResult`, `App\Enums\BroadcastIneligibilityReason`
- `App\Http\Controllers\Contacts\ContactController`
- Routes: `routes/contacts.php`

## Audit events

Uses Task 001 `AuditLogger`:

- `contact.created`
- `contact.updated` (excludes consent fields)
- `contact.consent_changed` — one per changed flag; old `{state, source, at}`, new `{consent, channel, state, source, at}`; actor and time from the audit row
- `contact.promoted`
- `contact.archived`
- `contact.restored`

## Future integration points

Contacts are ready to be referenced by later domains. Those relationships are **not** created yet:

- Contact → Conversations
- Customer → Quotes / Invoices / Payments / Recurring Billing / Documents

When Communication lands, WhatsApp/email identities on this record are the intended join points.

## UI

- `/contacts` list with search + status/type/archived filters
- Create / Edit / Show
- "WhatsApp communication" and "Email broadcasts" sections on Create (only with `contacts.update`) and Edit; each tick asks how consent was recorded. Show lists the current states
- Promote and Archive/Restore actions on the detail page
- Related-activity section is an explicit placeholder (no fake data)
