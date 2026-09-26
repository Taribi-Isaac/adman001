# 008 — WhatsApp Communication

## Purpose

WhatsApp is ADMAN’s second external communication channel, built on the Communication domain (Task 003) and the delivery architecture from Email (Task 007).

**Flows**

- Outbound: Staff action → pending `Message` → queue → WhatsApp Cloud API adapter → provider message id → status webhooks
- Inbound: Verified webhook → identity → conversation → inbound `Message` → (when AI customer responses enabled) `ProcessInboundAiMessage` → OpenAI + tools → session WhatsApp reply

Recurring invoice generation does **not** auto-send WhatsApp.

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

Inbound (+ AI when enabled):

```text
POST /webhooks/whatsapp
        ↓
Signature verification (X-Hub-Signature-256)
        ↓
WhatsAppInboundService → Message (+ ProcessInboundAiMessage)
        ↓
AiService → OpenAiCompatibleProvider → controlled tools
        ↓
WhatsAppOutboundService::queueAiSessionReply → sendText
```

---

## Implemented locally vs external setup

| Implemented in ADMAN | Requires Meta / ops setup |
|----------------------|---------------------------|
| Adapter, queue, webhook, UI, RBAC, tests (Http::fake) | Meta Business Portfolio + WhatsApp Business Account |
| Template name config | WhatsApp Cloud API app + approved templates |
| Opt-in flag on Contact | Production phone number + permanent system-user token |
| Secure document links in templates | Webhook URL + verify token + app secret |
| AI inbound session replies (Task 010/024B) | `messages` webhook subscription |

---

## Environment configuration

Placeholders in `.env.example` (never commit secrets):

- `ADMAN_WHATSAPP_ENABLED`
- `WHATSAPP_API_VERSION`, `WHATSAPP_API_BASE_URL`
- `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_BUSINESS_ACCOUNT_ID`
- `WHATSAPP_WEBHOOK_VERIFY_TOKEN`, `WHATSAPP_APP_SECRET`
- `WHATSAPP_TEMPLATE_LANGUAGE`
- `WHATSAPP_TEMPLATE_QUOTE`, `WHATSAPP_TEMPLATE_INVOICE`, `WHATSAPP_TEMPLATE_INVOICE_REMINDER`, `WHATSAPP_TEMPLATE_PAYMENT_ACK`
- `WHATSAPP_INBOUND_MEDIA_MAX_BYTES`

Business setting: `outbound_whatsapp_enabled` (org kill switch). Secrets stay in env.

### Production webhook URL

```text
https://adman.raslordeckltd.com/webhooks/whatsapp
```

- `GET` — Meta verify (`hub.mode=subscribe`, `hub.verify_token`, `hub.challenge`)
- `POST` — events; requires valid `X-Hub-Signature-256`

### Meta configuration path (current Cloud API docs)

1. Meta Business Portfolio → WhatsApp Business Account → WhatsApp-enabled phone number  
2. Meta Developer App → add **WhatsApp** product  
3. Generate a **permanent** system-user token with `whatsapp_business_messaging` + `whatsapp_business_management`  
4. Copy **Phone number ID** (and WABA id) into env  
5. App Dashboard → WhatsApp → Configuration: set Callback URL to the production webhook above; set Verify Token to match `WHATSAPP_WEBHOOK_VERIFY_TOKEN`  
6. Subscribe to the **`messages`** field  
7. Ensure App Secret matches `WHATSAPP_APP_SECRET` (signature validation)  
8. Approve outbound templates matching configured names (required for business-initiated messages outside the 24-hour customer-care window)

Official references: [Cloud API Get Started](https://developers.facebook.com/docs/whatsapp/cloud-api/get-started/), [Set up webhooks](https://developers.facebook.com/docs/whatsapp/cloud-api/guides/set-up-webhooks/).

### Task 028 status (2026-09-26) — PRODUCTION VERIFIED

| Item | State |
|------|--------|
| Production enablement | `ADMAN_WHATSAPP_ENABLED=true` |
| Real Meta inbound | Confirmed (`wamid.HBg…`) |
| AI → OpenAI → outbound | Confirmed via Horizon jobs |
| Provider acceptance + delivery status | Outbound messages carry Meta ids and reach ADMAN status **`delivered`** |
| GET verify / POST signature | 200 with valid token/signature; 403 otherwise |
| Live phone path | Verified in production against business number `+234 704 723 0179` |

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

1. Meta Business Portfolio + WhatsApp Business Account + WhatsApp Cloud API app  
2. Permanent system-user access token + Phone number ID (+ WABA id)  
3. Create/approve templates (`adman_quote`, `adman_invoice`, `adman_invoice_reminder`, `adman_payment_ack` or configured names)  
4. Set `WHATSAPP_WEBHOOK_VERIFY_TOKEN` + `WHATSAPP_APP_SECRET` (+ access token / phone number ID) in server `.env` only  
5. Rebuild Laravel config cache with least privilege (`umask 027`, `config.php` / routes cache `adman:www-data` **640**); keep `.env` at **600**; reload PHP-FPM; restart Horizon  
6. Meta Dashboard → WhatsApp → Configuration: Callback URL `https://adman.raslordeckltd.com/webhooks/whatsapp`, Verify Token = exact `WHATSAPP_WEBHOOK_VERIFY_TOKEN`, then **Verify and save** + subscribe to `messages`  
7. Set `ADMAN_WHATSAPP_ENABLED=true` only after credentials are complete; rebuild config cache again  
8. Confirm org `outbound_whatsapp_enabled`; mark controlled test contact `whatsapp_opt_in` for template sends  
9. Controlled outbound → controlled inbound → AI reply → payment claim / handoff verification  
