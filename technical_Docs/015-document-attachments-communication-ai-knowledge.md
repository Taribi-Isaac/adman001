# 015 — Document Attachments, Communication Settings & AI Business Knowledge

## Purpose

Extend communication and AI without new infrastructure:

1. Deliver **actual PDF attachments** on email and WhatsApp
2. Simplify customer-facing PDF presentation
3. Truthful `/settings/communication`
4. Structured, editable **AI business knowledge** (no RAG)
5. Secure inbound WhatsApp media storage + admin notification

Principle: MySQL/Laravel owns truth; communication delivers it; AI communicates within controlled boundaries.

---

## PDF attachments (email)

`EmailOutboundService` builds `EmailDeliveryPayload` with private-disk attachment fields from the existing `Document` row. `DocumentOutboundMail::attachments()` uses `Attachment::fromStorageDisk` with `application/pdf` and the document filename.

- Secure browser links remain secondary CTAs
- Missing PDF → validation failure → message marked failed (retryable path preserved)
- Queued via `SendOutboundEmailJob` unchanged

---

## PDF attachments (WhatsApp)

Primary customer delivery is no longer template-URL-only for document flows.

```text
Document PDF (private storage)
        ↓
WhatsAppDeliveryAdapter::uploadMedia
        ↓
WhatsAppDeliveryAdapter::sendDocument (type=document)
```

Message `meta.delivery_kind = document_pdf`. Adapter boundary holds Meta Graph media upload + document message. Session/template text paths unchanged.

**Ops note:** Meta may still require an approved template with a DOCUMENT header for business-initiated sends outside the 24h window. ADMAN sends the PDF as a Cloud API document message when credentials and messaging windows allow; configure Meta templates accordingly in production.

---

## Customer-facing PDFs

Shared header (`documents/partials/header.blade.php`) shows `legal_name` only when it differs from `name` (fixes duplicate business name).

Invoice / quote / payment acknowledgement metas no longer expose internal Lifecycle / Payment status / Due state / Currency labels. Financial totals and payment instructions remain.

---

## Communication settings

`GET /settings/communication` → `CommunicationSettingsController` / `settings/communication/Edit.vue`.

Shows channel enablement, sender/reply-to (from Business Settings), WhatsApp credential presence (not secrets), document delivery mode (`pdf_attachment`), and system message types. Does not duplicate AI / reminder / business identity editors.

---

## AI business knowledge

| Source | Storage |
|--------|---------|
| Profile / contact / payment instructions | `businesses` (+ `description`, `ai_support_instructions`) |
| Services/products | `business_offerings` |
| FAQ / policy / support / general | `business_knowledge_articles` |

`AiBusinessContextAssembler` injects bounded active knowledge into the AI system prompt. Customer-specific and financial data remain Laravel tools only. Unknown contacts receive general knowledge only.

UI: `/settings/knowledge` (`business.knowledge.view` / `manage`).

---

## Inbound attachments

```text
WhatsApp media → WhatsAppMediaClient::download → private local disk
  → message_attachments → InboundAttachmentReceived (database + mail)
  → conversation UI download / mark reviewed
```

- MIME allow-list + `WHATSAPP_INBOUND_MEDIA_MAX_BYTES`
- Safe filenames; no public URLs
- AI instructed to acknowledge receipt without claiming file review
- Does **not** auto-confirm payments

Permissions: `attachments.view`, `attachments.review`.

Audit: `attachment.inbound_stored`, `attachment.inbound_failed`, `attachment.reviewed`.

---

## Deferred

- OCR / AI vision of inbound files
- Personal WhatsApp/email forwarding of customer files
- RAG / embeddings
- Template CMS / document designer
