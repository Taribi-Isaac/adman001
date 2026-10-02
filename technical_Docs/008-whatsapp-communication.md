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

**Canonical format:** digits only, with the country code and no `+` (for example `2347054998090`). This is the value Meta sends as `from` / `wa_id`, and the value the adapter sends as `to`.

`App\Support\WhatsAppPhone::normalize` is the only normalizer. Every WhatsApp path goes through it: inbound sender and contact matching, outbound recipient (`fromContact`), staff-started conversations, broadcast eligibility, and `ConversationService::findOrCreateIdentity`.

Rules, in order:
1. Strip every non-digit (spaces, `+`, `-`, parentheses).
2. Strip a leading `00` international prefix.
3. Convert Nigerian mobile numbers (Task 040):
   - local form `0[789][01]` + 8 digits (`07054998090`, `0803…`, `0812…`, `0906…`) becomes `234` + the number without the leading 0;
   - `234` followed by the local trunk 0 (`+234 (0) 705…`) drops that 0.
4. Require 8–15 digits.

Equivalent inputs therefore resolve to the same value: `07054998090`, `0705 499 8090`, `+2347054998090`, `+234 (0) 705 499 8090`, `002347054998090` and `2347054998090` all become `2347054998090`.

Not converted:
- Numbers that already carry another country code are never treated as Nigerian, for example `+44 7911 123456` becomes `447911123456` and `+1 415…` becomes `1415…`.
- A leading 0 is only removed for the Nigerian mobile pattern; other numbers starting with 0 pass through unchanged.

For contacts, `fromContact` prefers `Contact.whatsapp_id`, else `Contact.phone`. Contact fields keep exactly what staff typed; only the identity and recipient values are canonical.

**Duplicate protection:**
- `communication_identities` has a unique index on `(channel, external_id)`.
- `findOrCreateIdentity` canonicalizes before `firstOrCreate`.
- A `creating` hook on `CommunicationIdentity` canonicalizes WhatsApp `external_id` for any other insert.

So a second identity for an equivalent spelling of an existing number cannot be created: the canonical value hits the unique index. The hook runs on create only, so legacy rows are never rewritten.

**Legacy duplicates (production, created before Task 040; kept as history, not merged):**

| Canonical identity | Legacy duplicate | Origin |
| --- | --- | --- |
| #7 `2347054998090` (Taribi Isaac) | #12 `07054998090` | Quote send on 2026-10-01 while the contact phone was `0705…`. One message, #121, failed with `131042`. |
| #5 `2349067322344` (unlinked) | #6 `09067322344` | Staff-started conversation on 2026-09-26. One staff message, #20, `recorded`. |

New activity for either number resolves to the canonical identity. If staff reply from an old legacy conversation, the recipient is still normalized to the canonical number. Proposed cleanup, as a separate owner-approved task:
1. Close the legacy conversation.
2. Deactivate the legacy identity (`is_active = false`).
3. Leave its messages and audits in place.

Re-pointing conversations to the canonical identity is possible, but it rewrites history and is not recommended.

Limitation: numbers written in local form are assumed to be Nigerian; there is no per-business default country. Non-Nigerian numbers must include their country code.

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

**Verified in production (Task 037R2, 2026-10-01):**
- **Window open:** the PDF goes as a plain WhatsApp document with the existing short caption, for example `Raslordeck Limited: Invoice INV-00002 (PDF attached)`. No template is used. The caption does **not** contain the secure link; this is the original design (the caption is unchanged since the first commit), not a 037R2 regression. A new secure link is still created and stored on the message (`meta.secure_url`).
- **Window closed:** the approved Utility template for the document is used, with the PDF as its DOCUMENT header and the secure link as the last body variable.

Each send creates a new secure link for the document and invalidates the previous one (the old URL returns 404).

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

### Current state (Task 037R2 closure, Graph API 2026-10-01 18:30 WAT) — all four live

| Key | Meta template | Status | Language / category | Header | Body variables | Prod `.env` name | Enabled |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `quote` | `quote_document` | APPROVED | `en` / Utility | Document | 3: name, quote number, secure link (business name is fixed text) | `quote_document` | yes |
| `invoice` | `invoice_sent` | APPROVED | `en` / Utility | Document | 3: name, invoice number, secure link | `invoice_sent` | yes |
| `invoice_reminder` | `invoice_reminder` | APPROVED | `en` / Utility | Document | 5: name, invoice number, balance, due date, secure link | `invoice_reminder` | yes |
| `payment_acknowledgement` | `payment_acknowledgement` | APPROVED | `en` / Utility | Document | 3: name, payment number, secure link | `payment_acknowledgement` | yes |

None has buttons. Each `_LANGUAGE` is `en`. The old placeholder names (`adman_quote`, `adman_invoice`, `adman_invoice_reminder`, `adman_payment_ack`) are no longer in the production `.env` or the cached config. `quote_sent` (approved, with {{3}} as the business name) is obsolete and unused; `hello_world` is the Meta sample and unused. WABA `health_status.can_send_message` is `AVAILABLE`.

### Production UAT (Task 037R2, 2026-10-01)

All sends went to the owner's own test number (contact "Taribi Isaac", identity `234…8090`), triggered by the owner from the normal ADMAN pages, one send per scenario, no retries. "Passed" means Meta reported `delivered`, the owner received the PDF as a WhatsApp document and opened it, and the secure link returned HTTP 200. Read receipts were not part of the criteria.

| Scenario | Template / mode | Message | Provider status | Result |
| --- | --- | ---: | --- | --- |
| Quote QT-00001, window closed | `quote_document` / `document_template` | #123 | delivered | Passed |
| Invoice INV-00002, window closed | `invoice_sent` / `document_template` | #124 | delivered | Passed |
| Reminder INV-00002, window closed (daily `reminders:process-due` command, occurrence #3) | `invoice_reminder` / `document_template` | #125 | delivered | Passed |
| Payment acknowledgement RCPT-00001, window closed | `payment_acknowledgement` / `document_template` | #126 | delivered | Passed |
| Invoice INV-00002, window open | direct `document_pdf`, no template | #128 | delivered | Passed |

Provider IDs (`wamid`) are stored on each message (`external_message_id`) and in the `whatsapp.sent` audit. Each send has a `whatsapp.queued` audit (staff actor, with `delivery_kind` and the template name for template sends) and a `whatsapp.sent` audit (system). The reminder was queued by the system actor.

**Payment acknowledgement.** RCPT-00001 is a genuine ₦1,000.00 bank-transfer payment on INV-00002. The owner recorded and confirmed it through the normal payment workflow (`payment.recorded` / `payment.confirmed` audits). The acknowledgement was sent only after confirmation, from the payment page; ADMAN generated `RCPT-00001-acknowledgement.pdf` and delivered it as the template DOCUMENT header. The send did not change the payment or the invoice: INV-00002 stayed partially paid (₦57,000 total, ₦1,000 paid, ₦56,000 outstanding), with 1 payment and 3 payment claims before and after. ADMAN has no payment reversal process, so this payment stays as a real record.

**In-window regression.** The owner first used **Take Over** on the conversation (so no AI reply would be sent), then sent one WhatsApp message from the test phone (inbound message #127). That opened the 24-hour window. The invoice send (#128) used `document_pdf`: the PDF as a plain document with the short caption, no template name or parameters sent. The secure link stored for that send returned HTTP 200 with the same PDF.

**History during the UAT.** Messages #121 and #122 (quote, `quote_document`) were accepted by Meta and then failed with `131042`: the WhatsApp account's payment method was blocked (`health_status` error `141006`). The owner fixed the Meta payment method and the retest (#123) was delivered. #121 also went to a separate identity created from a local-format phone number (see the finding below).

**Data finding: local-format phone numbers.** Contact phone `0705…` (no country code) normalizes to `070…`. ADMAN created a separate WhatsApp identity for it, not the existing `234…` identity the owner messages from. Meta still delivered by adding the business country code, but ADMAN checks the 24-hour window on the wrong identity, so that contact's window always looks closed. Store WhatsApp numbers with the country code (`+234…`), as the identity rules require (see Identity normalization). The UAT contact was corrected to `+234…`; the extra identity and its conversation remain as history. **Resolved in Task 040:** local-format numbers now normalize to `234…` (see Identity normalization).

### Post-037R2 follow-up items

- **Local phone-number normalization:** done in Task 040. The two legacy duplicate identities remain as history; see Identity normalization.
- **In-window secure-link caption:** consider adding the secure document link to the existing in-window document caption in a future, scoped improvement.
- **Test payment:** RCPT-00001 is a genuine confirmed ₦1,000 payment. There is no reversal workflow; leave it as is.
- **UAT contact reminder channel:** the owner's test contact still has reminder channel `both` (WhatsApp and email).
- **Broadcasts:** implemented in Task 038 (`017-broadcasts.md`); WhatsApp broadcasts need a separate approved **Marketing** template, which does not exist yet.

### Template reference (Utility)

These are the structures the approved templates follow. Use them if a template ever needs re-creating. The suggested names were placeholders; production uses the approved names in Current state above.

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
- Broadcast eligibility needs `whatsapp_opt_in` **and** a recorded `whatsapp_opt_in_at`, **plus** a separate recorded `whatsapp_broadcast_opt_in_at` (Task 038), plus no broadcast opt-out. Pre-Task-035 opt-ins (no timestamp) are not broadcast-eligible until staff record a source.
- Details: `002-contacts-domain.md` → Consent.

### Broadcast Marketing template (Task 038)

WhatsApp broadcasts (`017-broadcasts.md`) send only the template in `WHATSAPP_TEMPLATE_BROADCAST*` (delivery kind `broadcast_template`, no header, body variables only if configured). It must be an approved **Marketing** template and is refused if its name matches any Utility template above; there is no fallback. Broadcast messages are never retried, and account-level template/account errors stop the broadcast. As of 2026-10-01 Meta lists no Marketing template, so WhatsApp broadcasts cannot be sent.

### Transactional templates outside the window (Task 034 finding → Task 036)

Found in Task 034: outside the 24-hour window, ADMAN sent plain document messages, which Meta rejects (`131047`). Task 036 fixed the application side: out-of-window sends now use an enabled, approved Utility template with the PDF header, or are blocked with a clear reason (Outbound lifecycle, Templates).

**Closed (Task 037R2, 2026-10-01):** all four templates (`quote_document`, `invoice_sent`, `invoice_reminder`, `payment_acknowledgement`) are approved, enabled in production and tested live outside the window, and the in-window document path passed its regression test (Templates → Production UAT). Template names come from Meta; none are invented in code.

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
- Template-based re-engagement from the conversation thread when the 24-hour window is closed (staff free-text replies exist since Task 032; transactional document templates since Task 036)
- Broadcast scheduling, segmentation and campaign analytics (basic one-off broadcasts exist since Task 038, `017-broadcasts.md`)
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
