# 008 — WhatsApp Communication

## Purpose

WhatsApp is ADMAN’s second external communication channel, built on the Communication domain (Task 003) and the delivery architecture from Email (Task 007).

**Flows**

- Outbound: Staff action → pending `Message` → queue → WhatsApp Cloud API adapter → provider message id → status webhooks
- Inbound: Verified webhook → identity → conversation → inbound `Message` (no AI reply)

Recurring invoice generation does **not** auto-send WhatsApp. AI does **not** reply.

---

## Provider architecture

```text
WhatsAppOutboundService
        ↓
SendOutboundWhatsAppJob
        ↓
WhatsAppDeliveryAdapter
        ↓
WhatsAppCloudApiAdapter  →  Meta Graph / Cloud API
```

Inbound:

```text
POST /webhooks/whatsapp
        ↓
Signature verification (X-Hub-Signature-256)
        ↓
WhatsAppInboundService
        ↓
CommunicationIdentity / Conversation / Message
```

---

## Implemented locally vs external setup

| Implemented in ADMAN | Requires Meta / ops setup |
|----------------------|---------------------------|
| Adapter, queue, webhook, UI, RBAC, tests (Http::fake) | WhatsApp Business / Meta app |
| Template name config | Approved message templates |
| Opt-in flag on Contact | Real customer opt-in process |
| Secure document links in templates | Production webhook URL + tokens |

---

## Environment configuration

Placeholders in `.env.example` (never commit secrets):

- `ADMAN_WHATSAPP_ENABLED`
- `WHATSAPP_API_VERSION`, `WHATSAPP_API_BASE_URL`
- `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_BUSINESS_ACCOUNT_ID`
- `WHATSAPP_WEBHOOK_VERIFY_TOKEN`, `WHATSAPP_APP_SECRET`
- `WHATSAPP_TEMPLATE_LANGUAGE`
- `WHATSAPP_TEMPLATE_QUOTE`, `WHATSAPP_TEMPLATE_INVOICE`, `WHATSAPP_TEMPLATE_PAYMENT_ACK`

Business setting: `outbound_whatsapp_enabled` (org kill switch). Secrets stay in env.

---

## Identity normalization

`WhatsAppPhone::normalize`:

- Strip non-digits
- Strip leading `00` international prefix
- Require 8–15 digits
- Prefer `Contact.whatsapp_id`, else `Contact.phone`

Stored as `CommunicationIdentity.external_id` with `channel=whatsapp`.

Unknown inbound senders create an identity **without** creating a Customer.

---

## Outbound lifecycle

`pending` → `processing` → `sent` → (`delivered` / `read` via webhook) or `failed`

Document sends use **`delivery_kind=document_pdf`**:

1. Ensure PDF exists on private storage  
2. Upload media to Meta (`WhatsAppDeliveryAdapter::uploadMedia`)  
3. Send WhatsApp **document** message with caption (`sendDocument`)  

Secure document URLs may still be stored on the message for internal/browser use but are not the primary customer delivery mechanism.

Requires:

- Customer contact
- `whatsapp_opt_in = true`
- Valid WhatsApp number
- Eligible document (not draft / unconfirmed payment)
- Readable private PDF file

---

## Inbound webhook

- `GET /webhooks/whatsapp` — Meta verify challenge
- `POST /webhooks/whatsapp` — events (CSRF exempt)

Verification: `hub.verify_token` must match `WHATSAPP_WEBHOOK_VERIFY_TOKEN`.

POST authenticity: HMAC SHA-256 of raw body with `WHATSAPP_APP_SECRET` in `X-Hub-Signature-256`.  
If app secret is empty, signatures are only skipped outside production (local scaffolding).

---

## Idempotency

- Inbound messages: unique `(channel, external_message_id)`; duplicates ignored
- Status webhooks: forward-only rank (`sent` < `delivered` < `read`); duplicates harmless
- Outbound job: `ShouldBeUnique` + `lockForUpdate`; terminal success skips re-send
- Retry reuses the same message row

Guarantee: **at-least-once** processing; external exactly-once cannot be promised.

---

## Templates

Env-configured names. Operators must create matching Meta templates with three body variables as above.

---

## Document sharing

Business documents are delivered as **WhatsApp PDF document attachments** (media upload + document message). Private storage paths are never exposed as public URLs. Secure links may remain for browser viewing.

Outside the customer-care window, Meta may still require approved templates (e.g. DOCUMENT header). Configure production templates to match how ADMAN sends documents.

---

## Conversation / human control

WhatsApp threads use the existing Conversations UI.

- **Human**: messages displayed; **no automatic replies**
- **Closed**: stays closed; new inbound opens a **new** open conversation
- **AI** mode: Task 010 may auto-reply when AI customer responses are enabled

---

## Opt-in / compliance

`contacts.whatsapp_opt_in` must be true for business-initiated template sends.  
Meta’s messaging policies and 24-hour customer-care windows still apply at the provider; ADMAN does not replace them. Session free-form staff chat from Conversations remains internal-record unless extended later.

---

## Permissions & audit

Reuse `messages.send` / `messages.retry` / `messages.view`.

Audit: `whatsapp.queued`, `whatsapp.sent`, `whatsapp.failed`, `whatsapp.retry_queued`, `whatsapp.inbound_unknown`, `whatsapp.webhook_rejected`  
(Not every delivery-status webhook.)

---

## Rate limiting / retry

Job: 3 tries, backoff 30/120/300s. Permanent provider errors (auth, invalid template/recipient) are marked failed without infinite retry. 429/5xx remain retryable.

---

## Limitations / deferred

- OCR / vision of inbound customer files
- Automated recurring auto-send of invoices (invoice reminders owned by Task 009)
- Broadcast / marketing campaigns
- Staff free-form WhatsApp composer (AI session text exists in Task 010; staff compose remains internal-record by default)
- Delivery/read UI polish beyond status labels
- Personal forwarding of inbound files to arbitrary admin phone numbers

---

## External setup checklist

1. Meta Business + WhatsApp Cloud API app  
2. Phone number ID + permanent access token  
3. Create/approve templates (`adman_quote`, `adman_invoice`, `adman_payment_ack` or configured names)  
4. Webhook URL: `https://<host>/webhooks/whatsapp`  
5. Verify token + app secret in env  
6. Subscribe to `messages` field  
7. Enable org WhatsApp in Business settings; mark customer opt-in  
8. Run queue workers / Horizon  

This documentation does not claim Meta setup was completed in the development environment.
