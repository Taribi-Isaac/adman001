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
| address fields | Optional |
| `notes` | Optional internal notes |
| `archived_at` | Null when active |

Indexed for status, type, display_name, email, phone, whatsapp_id, archived_at.

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
- `App\Http\Controllers\Contacts\ContactController`
- Routes: `routes/contacts.php`

## Audit events

Uses Task 001 `AuditLogger`:

- `contact.created`
- `contact.updated`
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
- Promote and Archive/Restore actions on the detail page
- Related-activity section is an explicit placeholder (no fake data)
