# ADMAN Technical Documentation — Payments Domain

## Purpose

Manual/offline payment foundation for the MVP:

```text
Invoice → Payment Claim / Manual Payment → Verification → Confirmed Payment
       → Invoice Balance / Payment State → Payment Acknowledgement
```

No payment gateways, bank APIs, wallets, or accounting ledger.

## Payment vs Payment Claim

| Concept | Role |
| --- | --- |
| **Payment Claim** | Customer/staff assertion that money was sent. Not financially authoritative. |
| **Payment** | Business financial record. Only **Confirmed** payments affect invoice balance. |

Rules:

- Pending/awaiting/rejected claims never reduce outstanding balance.
- Confirming a claim creates and confirms a Payment (idempotent).
- Rejecting a claim creates no Payment.

## Payment lifecycle

```text
Pending → Confirmed | Rejected
```

Staff with `payments.record` may create Pending payments.  
Staff with `payments.confirm` may confirm (including `confirm_immediately` on record).  
Confirmed payments are immutable in this MVP (no reversal/edit) — see Decisions.

## Payment claim lifecycle

```text
Pending Verification ↔ Awaiting Information → Confirmed | Rejected
```

## Partial & multiple payments

Invoice 1 → Many Payments.

Outstanding = Invoice Total − Sum(Confirmed Payments).

Payment state on invoice (derived):

| State | Rule |
| --- | --- |
| Unpaid | Confirmed total = 0 |
| Partially Paid | 0 < confirmed total < invoice total |
| Paid | Confirmed total = invoice total |

Due-date state remains independent (e.g. Partially Paid + Overdue is valid).

## Integrity controls

- Payments only against **Issued** invoices (not Draft/Cancelled).
- Amount > 0; cannot exceed outstanding (no overpayment/credit system).
- Invoice locked (`lockForUpdate`) during confirm/record-confirm/claim-confirm.
- Claim confirmation idempotent via status + unique `payment_id`.
- BCMath via `App\Support\Money`.

## Numbering

Payment numbers use Business `receipt_number_prefix` + `receipt_next_sequence` (e.g. `RCPT-00001`).

## Acknowledgements

- Document type: `payment_acknowledgement_pdf`
- Only for **Confirmed** payments
- Snapshots: business, customer, invoice totals before/after
- Partial payments explicitly labeled as not fully settled
- Reuses secure share-link mechanism (`/d/{token}`)

## Permissions

- `payments.view|record|confirm|reject`
- `payments.claims.view|create|review`
- `payments.acknowledgements.generate`

## Audit

- `payment.recorded|confirmed|rejected`
- `payment_claim.created|confirmed|rejected|information_requested`
- `document.generated` / secure link events for acknowledgements

## Deferred

- Gateways (Paystack, Stripe, etc.)
- Refunds / payment reversals / credit notes
- WhatsApp/email sending of acknowledgements
- AI claim collection (must never confirm payment)

## Key modules

- `app/Services/PaymentService.php`
- `app/Http/Controllers/Payments/*`
- `routes/payments.php`
- `resources/js/pages/payments/*`
- `resources/js/pages/payment-claims/*`
- `tests/Feature/PaymentTest.php`
