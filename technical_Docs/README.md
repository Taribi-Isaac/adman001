# ADMAN Technical Documentation

## Purpose

`technical_Docs/` holds engineering documentation for the ADMAN Laravel modular monolith.

These documents explain **what exists**, **where it lives**, and **why key decisions were made**.

## Source of truth

Approved product requirements live in `foundational_docs/`:

- ADMAN BUSINESS REQUIREMENTS DOCUMENT (BRD)
- ADMAN PRODUCT REQUIREMENTS DOCUMENT (PRD)
- ADMAN UX_UI SPECIFICATION
- adman SOURCE OF TRUTH

When documentation and code diverge, **the current implementation/code takes precedence**. Update docs when making significant implementation changes.

## Domain / task documents

| Document | Topic |
| --- | --- |
| `001-application-foundation.md` | Laravel/Inertia foundation, auth, RBAC, business settings, audit, queues |
| `002-contacts-domain.md` | Contacts / customers |
| `003-communication-domain.md` | Conversations, identities, messages |
| `004-quotes-invoices-documents.md` | Quotes, invoices, DomPDF documents, secure links, branding on PDFs |
| `005-payments-domain.md` | Manual payments, payment claims, acknowledgements |
| `006-recurring-billing-domain.md` | Recurring invoice schedules |
| `007-email-delivery.md` | Outbound email document delivery |
| `008-whatsapp-communication.md` | WhatsApp Cloud API inbound/outbound |
| `009-invoice-reminders.md` | Invoice reminder automation |
| `010-ai-human-handoff.md` | Controlled AI tools and human handoff |
| `011-production-readiness.md` | Production safety checks and hardening |
| `012-staging-deployment-and-uat.md` | Staging/UAT plan, local pre-deploy verification (Task 014), smoke/UAT checklists |
| `015-document-attachments-communication-ai-knowledge.md` | PDF attachments (email/WhatsApp), simplified PDFs, communication settings, AI knowledge, inbound media |
| `016-production-deployment.md` | Production Droplet provisioning, SSH/firewall/swap, deploy runbook (Task 017+) |

## Maintenance

- Prefer concise, practical notes over speculative catalogues.
- Do not create empty stubs for unapproved features.
- Document deferred items explicitly when relevant.
- Keep docs aligned with the principle: **Simple → Correct → Secure → Maintainable → Testable → Scalable when needed**.
