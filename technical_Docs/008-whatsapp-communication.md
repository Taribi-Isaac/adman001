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
- `WHATSAPP_TEMPLATE_LANGUAGE` (default language for all templates)
- `WHATSAPP_TEMPLATE_QUOTE`, `WHATSAPP_TEMPLATE_INVOICE`, `WHATSAPP_TEMPLATE_INVOICE_REMINDER`, `WHATSAPP_TEMPLATE_PAYMENT_ACK` (names; no code default)
- `WHATSAPP_TEMPLATE_<KEY>_ENABLED` (default `false`) and optional `WHATSAPP_TEMPLATE_<KEY>_LANGUAGE` for each of the four keys above — see Templates
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

Transactional document sends (quote, invoice, invoice reminder, payment acknowledgement) choose a mode from the **24-hour customer service window** (Task 036). The window is open for 24 hours after the latest inbound WhatsApp message stored for the customer's identity, across all its conversations (`customerServiceWindowOpen`). A customer who has never messaged counts as closed.

| Window | Template for this document enabled? | Result (`meta.delivery_kind`) |
| --- | --- | --- |
| Open | not needed | `document_pdf`: upload PDF, then send a normal **document** message with caption |
| Closed | yes | `document_template`: upload PDF, then send the approved Utility **template** with the PDF as its DOCUMENT header |
| Closed | no | **Blocked**: nothing stored or sent; `whatsapp.blocked` audit; staff see the reason on the page; reminders become `not_deliverable` with the reason |

The decision is made when queuing and **again at send time** (`deliverDocument`), because the window can open or close between queue, delay and retry. If it closed and no template is enabled, the message fails with a clear reason, is not retried, and the provider is not called. Retrying it is refused until a template is enabled or the customer messages again. The mode actually used is written back to `meta.delivery_kind` (and `template_name` / `template_language`).

The PDF is always an attachment (document message, or template DOCUMENT header). The secure link is still created: it's stored on the message for browser use, and it's the last template body variable (link fallback).

Requires (unchanged):

- Customer contact
- `whatsapp_opt_in = true` (transactional gate; broadcast opt-out and email consent are **not** consulted)
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

Templates are created and approved in Meta (WhatsApp Manager). ADMAN only consumes them (`App\Support\WhatsAppTransactionalTemplate`, `config/adman.php` → `whatsapp.templates.<key>`: `name`, `language`, `enabled`). A template is used only when **`enabled` is true and a name is set**. Enabled defaults to `false`, so a name alone (application configuration) is never treated as a Meta-approved template.

### Current state (Graph API, 2026-10-01 10:26 and 10:55 WAT, Task 037R2)

| Key | Meta template | Status | Language / category | Header | Body variables | Matches ADMAN | Prod `.env` | ADMAN enabled | Live UAT |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `quote` | `quote_document` | APPROVED | `en` / Utility | Document | 3: name, quote number, secure link (business name "Raslordeck Limited" is fixed text) | yes | `quote_document`, `_LANGUAGE=en` | **no** (enabled for the UAT, then disabled) | accepted by Meta, then failed: account payment method (see below) |
| `invoice` | `invoice_sent` | APPROVED | `en` / Utility | Document | 3: name, invoice number, secure link | yes | `adman_invoice` (not switched yet) | no | not run |
| `invoice_reminder` | `invoice_reminder` | **PENDING** | `en` / Utility | Document | 5: name, invoice number, balance, due date, secure link | yes (structure) | `adman_invoice_reminder` | no | not run |
| `payment_acknowledgement` | `payment_acknowledgement` | **PENDING** | `en` / Utility | Document | 3: name, payment number, secure link | yes (structure) | `adman_payment_ack` | no | not run |

`quote_sent` (approved, with {{3}} as the business name) is obsolete and not used. `hello_world` is the Meta sample and is unused.

**Blocker: WhatsApp account payment method.** The WABA `health_status` reports `can_send_message: BLOCKED`, error `141006`: "There is an error with the payment method. This will block business initiated conversations." Every template send is business-initiated, so none can be delivered until the owner adds a valid payment method in Meta Business Settings → WhatsApp accounts → Payment settings. In-window replies are not affected.

Quote UAT (2026-10-01 10:49, sent by the owner from the quote page, QT-00001 to the owner's own number, window closed):
- ADMAN chose `document_template` with `quote_document`/`en`;
- parameters were [contact name, `QT-00001`, secure link], plus the `QT-00001.pdf` document header;
- Meta accepted the send (provider ID recorded, `whatsapp.queued` and `whatsapp.sent` audits);
- the status webhook then failed it with `131042`, shown as "WhatsApp account payment issue…";
- one message, no retry, no duplicate.

So the template path and the failure handling are proven, but **delivery and PDF receipt are not**. The template was disabled again afterwards. In-window document sends keep working; out-of-window sends stay blocked until templates are re-enabled.

**Data finding: local-format phone numbers.** Contact phone `0705…` (no country code) normalizes to `070…`. ADMAN created a separate WhatsApp identity for it, not the existing `234…` identity the owner messages from. Meta still delivered by adding the business country code, but ADMAN checks the 24-hour window on the wrong identity, so that contact's window always looks closed. Store WhatsApp numbers with the country code (`+234…`), as the identity rules require (see Identity normalization).

### Templates the owner needs to create (Utility)

All four: category **Utility**, language to match `WHATSAPP_TEMPLATE_LANGUAGE` (currently `en`; if you create them as `en_US` or `en_GB`, set that value or the per-template `_LANGUAGE`). Header type **Document** (Meta asks for a sample PDF when you submit), and **no buttons**. The body must not start or end with a variable. Suggested names match the current configuration; any approved name works if the env is updated.

| Purpose | Suggested name | Body variables (ADMAN data) | Suggested body text |
| --- | --- | --- | --- |
| Quote delivery | `adman_quote` | {{1}} contact display name · {{2}} quote number · {{3}} secure document link | `Hello {{1}}, please find attached your quote {{2}} from <business name>. You can also view it here: {{3}} Reply to this message if you have any questions.` |
| Invoice delivery | `adman_invoice` | {{1}} contact display name · {{2}} invoice number · {{3}} secure document link | `Hello {{1}}, your invoice {{2}} from <business name> is attached. You can also view it here: {{3}} Thank you for your business.` |
| Invoice reminder | `adman_invoice_reminder` | {{1}} contact display name · {{2}} invoice number · {{3}} outstanding balance with currency (e.g. `NGN 25,000.00`) · {{4}} due date (e.g. `7 Oct 2026`) · {{5}} secure document link | `Hello {{1}}, this is a reminder that invoice {{2}} has an outstanding balance of {{3}}, due {{4}}. The invoice is attached and can also be viewed here: {{5}} Please ignore this message if you have already paid.` |
| Payment acknowledgement | `adman_payment_ack` | {{1}} contact display name · {{2}} payment reference number · {{3}} secure document link | `Hello {{1}}, thank you. We have received your payment {{2}}. Your acknowledgement is attached and can also be viewed here: {{3}} Thank you.` |

The parameter order is fixed in code (`WhatsAppOutboundService::templateBodyParameters`). Meta must approve the same number of body variables, or sends fail with a "parameters do not match" error. Values are sent with whitespace collapsed; an empty value is sent as `-`.

### Enabling after Meta approval (owner and engineer)

1. Confirm the template shows **Approved** in WhatsApp Manager, with the exact name and language.
2. In the server `.env`: set `WHATSAPP_TEMPLATE_<KEY>` to the approved name, `WHATSAPP_TEMPLATE_<KEY>_ENABLED=true`, and the language if it differs. Keys: `QUOTE`, `INVOICE`, `INVOICE_REMINDER`, `PAYMENT_ACK`.
3. Rebuild the config cache with least privilege (see External setup checklist step 5), then restart Horizon.
4. Test once with the owner's test number, with the window closed (no message from that number in the last 24 hours).

Enable each template separately; unapproved ones stay disabled.

Before enabling, check each template read-only through the Graph API (`GET /{business_account_id}/message_templates`). It needs:
- status `APPROVED`;
- category `UTILITY`;
- a language code that matches the configuration exactly (WhatsApp Manager "English" is `en`; "English (US)" is `en_US`);
- a `DOCUMENT` header;
- the body variable count from the table above (3, or 5 for the reminder);
- no buttons.

Anything else means the template isn't enabled.

### Failure visibility

Send errors and failure webhooks use `App\WhatsApp\WhatsAppErrorMapper` for staff-safe reasons:

- window closed (`131047`);
- template missing (`132001`);
- parameter mismatch (`132000` / `132012`);
- content rejected (`132005` / `132007`);
- template paused (`132015`) or disabled (`132016`);
- recipient stopped receiving (`131050`);
- undeliverable (`131026`);
- Meta per-user limits (`131049`);
- payment issue (`131042`);
- account locked / restricted (`131031` / `368`);
- quality limits (`131048`);
- rate limits (`130429` / `131056`, retryable);
- authentication.

Other 4xx errors are not retried; 429/5xx are. Reasons appear on the message (`failure_reason`) and in the delivery lists on the quote, invoice and payment pages; nothing raw is shown to customers.

---

## Document sharing

Business documents are delivered as **WhatsApp PDF attachments**: a document message inside the window, or the template DOCUMENT header outside it. Private storage paths are never exposed as public URLs. Secure links remain for browser viewing and as the template link fallback.

---

## Conversation / human control

WhatsApp threads use the existing Conversations UI.

- **Human**: messages displayed; **no automatic replies**; the assigned staff member can send free-text **WhatsApp replies** from the thread (Task 032, below)
- **Closed**: stays closed; new inbound opens a **new** open conversation
- **AI** mode: Task 010 may auto-reply when AI customer responses are enabled

---

## Outbound text formatting (Task 031)

AI output often contains Markdown, which WhatsApp does not render. Session text (`delivery_kind = session_text`, i.e. AI replies) is passed through `App\WhatsApp\WhatsAppTextFormatter` **at delivery time only**; the stored message body keeps the original AI text. Template and document sends are not touched.

| Markdown | Sent to WhatsApp |
| --- | --- |
| `[label](https://url)` / `<https://url>` | `https://url` (bare URL; WhatsApp auto-links it) |
| `**bold**` / `__bold__` | `*bold*` |
| `*italic*` (single asterisks) | `_italic_` |
| `# Heading` … `###### Heading` | `*Heading*` |

Plain URLs, query strings, email addresses, line breaks, `-`/`*` bullets, numbered lists and ordinary punctuation are left unchanged. The AI system prompt also asks for plain WhatsApp text with bare URLs (secondary; the formatter is the guarantee). Tests: `tests/Unit/WhatsAppTextFormatterTest.php`.

---

## Opt-in / compliance

`contacts.whatsapp_opt_in` must be true for business-initiated sends (document messages and templates).  
Meta’s messaging policies and 24-hour customer-care windows still apply at the provider; ADMAN does not replace them.

### Opt-in vs broadcast opt-out (Task 035)

- `whatsapp_opt_in` keeps its meaning: the customer agreed to business WhatsApp messages. It gates document sends (`WhatsAppOutboundService::requireEligibleContact`) and WhatsApp reminders (`ReminderService`). Task 035 adds evidence columns (`whatsapp_opt_in_at`, `whatsapp_opt_in_source`) but does not change the gate.
- `whatsapp_broadcast_opt_out_at` records that the customer does not want **broadcasts**. It is read only by `BroadcastEligibilityService`. Invoices, quotes, payment acknowledgements, reminders and staff/AI replies are unaffected (covered by `tests/Feature/ContactConsentTest.php`).
- Broadcast eligibility needs `whatsapp_opt_in` **and** a recorded `whatsapp_opt_in_at`, plus no broadcast opt-out. Pre-Task-035 opt-ins (no timestamp) are not broadcast-eligible until staff record a source.
- Details: `002-contacts-domain.md` → Consent.

### Transactional templates outside the window (Task 034 finding → Task 036)

Found in Task 034: outside the 24-hour window, ADMAN sent plain document messages, which Meta rejects (`131047`). Task 036 fixed the application side: out-of-window sends now use an enabled, approved Utility template with the PDF header, or are blocked with a clear reason (Outbound lifecycle, Templates).

**Update (Task 037R2, 2026-10-01):** `quote_document` and `invoice_sent` are approved and match ADMAN. `invoice_reminder` and `payment_acknowledgement` match but are still pending in Meta. The live quote test showed that the WhatsApp account's payment method blocks every business-initiated (template) message (`141006` / `131042`). All four remain disabled.

**Still open, owner action:**
- fix the Meta payment method;
- wait for the two pending approvals.

Then enable and test each template live. Template names and IDs come from Meta; none are invented in code.

### Customer service window & staff replies (Task 032)

Meta rule (Cloud API "Service messages", checked 2026-09-29): a user's message or call opens a **24-hour customer service window**, reset by each new user message; free-form (non-template) messages are allowed only while it is open, otherwise only approved templates.

ADMAN applies this before queueing a staff free-text reply, using stored data only (no extra API call): the window ends 24 hours after the latest **inbound WhatsApp message** for the conversation's identity (across its conversations) — `WhatsAppOutboundService::customerServiceWindowExpiresAt()`. Calls are not recorded by ADMAN, so a call-only contact counts as closed.

- Window open → reply queued as `session_text` on the existing `SendOutboundWhatsAppJob` (unique per message, 3 tries, backoff 30/120/300 s). Staff text is sent unformatted; only AI-authored session text goes through `WhatsAppTextFormatter`.
- Window closed → nothing is queued or stored; the composer is disabled with an explanation. Template-based re-engagement is **not** available from the thread (no template management yet).
- Retrying a failed session-text message is refused once the window has closed.
- If Meta still rejects with error `131047` (window closed), the message is marked failed (not retried) with a clear staff-facing reason.

Ownership, permission (`messages.send`) and state rules: see `003-communication-domain.md` → Staff WhatsApp reply.

---

## Permissions & audit

Reuse `messages.send` / `messages.retry` / `messages.view`.

Audit: `whatsapp.queued` (with `delivery_kind`), `whatsapp.blocked` (window closed, no enabled template; on the quote/invoice/payment), `whatsapp.ai_queued`, `whatsapp.staff_reply_queued`, `whatsapp.sent`, `whatsapp.failed`, `whatsapp.retry_queued`, `whatsapp.inbound_unknown`, `whatsapp.webhook_rejected`  
(Not every delivery-status webhook.)

---

## Rate limiting / retry

Job: 3 tries, backoff 30/120/300s. Permanent provider errors (auth, invalid template/recipient) are marked failed without infinite retry. 429/5xx remain retryable.

---

## Limitations / deferred

- OCR / vision of inbound customer files
- Automated recurring auto-send of invoices (invoice reminders owned by Task 009)
- Broadcast / marketing campaigns
- Template-based re-engagement from the conversation thread when the 24-hour window is closed (staff free-text replies exist since Task 032; transactional document templates since Task 036)
- Broadcast / bulk WhatsApp and email messaging (consent foundation + eligibility exist since Task 035; recipients, templates, scheduling, rate limits and sending remain future work)
- Delivery/read UI polish beyond status labels
- Personal forwarding of inbound files to arbitrary admin phone numbers

---

## External setup checklist

1. Meta Business Portfolio + WhatsApp Business Account + WhatsApp Cloud API app  
2. Permanent system-user access token + Phone number ID (+ WABA id)  
3. Create/approve the four Utility templates with a DOCUMENT header (see Templates), then set `WHATSAPP_TEMPLATE_<KEY>_ENABLED=true` for each approved one  
4. Set `WHATSAPP_WEBHOOK_VERIFY_TOKEN` + `WHATSAPP_APP_SECRET` (+ access token / phone number ID) in server `.env` only  
5. Rebuild Laravel config cache with least privilege (`umask 027`, `config.php` / routes cache `adman:www-data` **640**); keep `.env` at **600**; reload PHP-FPM; restart Horizon  
6. Meta Dashboard → WhatsApp → Configuration: Callback URL `https://adman.raslordeckltd.com/webhooks/whatsapp`, Verify Token = exact `WHATSAPP_WEBHOOK_VERIFY_TOKEN`, then **Verify and save** + subscribe to `messages`  
7. Set `ADMAN_WHATSAPP_ENABLED=true` only after credentials are complete; rebuild config cache again  
8. Confirm org `outbound_whatsapp_enabled`; mark controlled test contact `whatsapp_opt_in` for document/template sends  
9. Controlled outbound → controlled inbound → AI reply → payment claim / handoff verification  
