# ADMAN Technical Documentation — Quotes, Invoices & Documents

## Purpose

Establish the commercial document workflow:

```text
Customer → Quote → Invoice → Document
```

Payments, email/WhatsApp sending, receipts, and recurring billing remain deferred.

## Quote lifecycle

```text
Draft → Issued → Accepted | Rejected | Expired
              ↘ Cancelled (when not accepted / not converted)
```

Rules:

- Only **Customer** contacts may be quoted.
- Drafts are editable; totals recalculated server-side.
- Issue freezes **business_snapshot** and **customer_snapshot**.
- Issued commercial values are immutable (cancel/reissue rather than mutate).
- Expired: issued quotes past `expiry_date` (business-local) are transitioned to `expired` when refreshed.
- Convert requires **Accepted**; creates one invoice (`invoices.quote_id` unique). Repeated convert is idempotent.

## Invoice lifecycle (orthogonal state)

### Lifecycle status (persisted)

`draft` | `issued` | `cancelled`

### Payment status (persisted)

`unpaid` | `partially_paid` | `paid`

Until the Payments domain exists, issued invoices remain **unpaid** with `amount_paid = 0` and `balance_due = total`. No payment records are fabricated.

**Outstanding** is derived: unpaid or partially paid with balance &gt; 0.

### Due-date state (derived, not persisted)

From issue/due dates + business-local today + lifecycle/payment:

`not_applicable` | `not_due` | `due_today` | `overdue`

### UI display badge precedence

Cancelled → Paid → Overdue → Due today → Partially paid → Unpaid → Issued → Draft

The badge is convenience only; underlying facts remain separate.

## Quote → Invoice conversion

- Deliberate staff action (`quotes.convert`).
- Creates a **draft** invoice with independent number, copied lines/totals, due date from `Business.default_payment_term_days` (default **14**).
- Snapshots are taken when the **invoice is issued** (fresh business + customer data at that moment).
- One invoice per quote (unique `quote_id`).

## Calculation order (locked)

1. Line subtotal = quantity × unit price  
2. Document discount (percentage or fixed)  
3. Taxable = subtotal − discount  
4. Tax on taxable (if enabled)  
5. Grand total = taxable + tax  

Server is authoritative (`App\Support\DocumentCalculator` + `App\Support\Money` / BCMath).

### Rounding

Currency amounts use scale **2**. Rates use scale **4**. BCMath half-up via `bcmul`/`bcadd` at the target scale. Discount cannot exceed subtotal (rejects negative taxable amounts).

## Numbering

Atomic sequences on `businesses`:

- `quote_next_sequence` / `invoice_next_sequence`
- Format: `{prefix}{#####}` e.g. `QT-00001`, `INV-00001`
- Prefixes from Business Settings (`quote_number_prefix`, `invoice_number_prefix`)

## Snapshots

On issue, store JSON snapshots of document-relevant business and customer fields. Later Business/Contact edits do not rewrite issued documents.

## Documents & PDF

- Package: **barryvdh/laravel-dompdf** v3
- Types: `quote_pdf`, `invoice_pdf`, `payment_acknowledgement_pdf`
- Stored on the `local` disk under `documents/...`
- Generation audited; PDF failure is separate from commercial record creation
- Shared presentation: `resources/views/documents/partials/*` + `App\Support\DocumentPresentation`
- Customer-facing PDFs omit internal lifecycle/payment/due-state labels; show customer-useful financial fields only
- Header shows `legal_name` only when it differs from `name` (avoids duplicate business title)
- DomPDF-safe table layout (no floats for critical sections)
- Bill To name fallback: display_name → organization_name → first+last → `Customer`
- Logo: snapshot `logo_path` when the private file still exists; otherwise `public/ADMAN-LOGO.png` (data URI embedded in PDF)
- Snapshot immutability: changing/removing the current business logo does **not** rewrite historical `business_snapshot` values

## Secure document links

- Opaque 64-char random token; only **SHA-256 hash** stored
- Public route: `GET /d/{token}` (`documents.secure`) — unauthenticated, not a portal
- Default TTL: **90 days** (`DocumentService::SECURE_LINK_TTL_DAYS`)
- Staff may revoke (`access_revoked_at`) or create a new link
- Drafts cannot generate public PDFs; secure access returns 404 for drafts / revoked / expired / invalid tokens without leaking details

## Permissions

Quotes: `quotes.view|create|update|issue|accept|reject|cancel|convert`  
Invoices: `invoices.view|create|update|issue|cancel`  
Documents: `documents.view|generate|revoke_link`

## Audit events

Quotes: `quote.created|issued|accepted|rejected|cancelled|converted`  
Invoices: `invoice.created|issued|cancelled`  
Documents: `document.generated`, `document.secure_link_created`, `document.secure_link_revoked`

## Deferred

- Document accent-color configuration / multiple templates / document designer
- Credit notes / refunds
- Inventory / catalog
- Customer portal
- Dynamic favicon / auth-page business logo

PDF **attachments** for email/WhatsApp delivery are implemented in Task 015 (see `015-document-attachments-communication-ai-knowledge.md` and 007/008).

## Key modules

- `app/Services/{Quote,Invoice,Document}Service.php`
- `app/Support/{Money,DocumentCalculator,DocumentNumbering,DocumentSnapshots,DocumentPresentation}.php`
- `app/Http/Controllers/DashboardController.php`
- `routes/{quotes,invoices,documents}.php`
- `resources/js/pages/{quotes,invoices,documents,Dashboard}/*`
- `resources/js/pages/settings/business/Edit.vue` (branding)
- `tests/Feature/QuoteInvoiceDocumentTest.php`
- `tests/Feature/DocumentBrandingDashboardTest.php`
- `tests/Unit/DocumentCalculatorTest.php`
- `tests/Unit/DocumentPresentationTest.php`
