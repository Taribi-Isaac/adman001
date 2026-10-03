# ADMAN Technical Documentation — Broadcasts (Task 038)

## Purpose

A small, controlled way to send one message to customers who **explicitly agreed** to receive broadcasts, on one channel:

- **WhatsApp:** only an approved Meta **Marketing** template. Never free-form text, never a transactional Utility template.
- **Email:** subject + plain-text body through the existing queued email pipeline (Resend in production). No attachments.

There is no scheduling, segmentation, A/B testing, analytics, or automation. Each broadcast is a one-off that an authorised person reviews and sends by hand.

## Production state (2026-10-03, after Task 046)

| Gate | State |
| --- | --- |
| `businesses.broadcasts_enabled` | **false**: no real campaign can currently be sent. It was on for under a second for each controlled UAT (Task 041 WhatsApp, Task 042 email), then returned to off. Every change is audited as `business.settings_updated`. |
| WhatsApp UAT | **PASS** (Task 041, sent with the UAT template `broadcast_test`): broadcast #1, 1 recipient, delivered |
| Email UAT | **PASS** (Task 042): broadcast #2, 1 recipient, delivered according to Resend |
| Configured WhatsApp template | **`raslordeck_broadcast`** / `en` / enabled / `contact_name`: the production Marketing template (Task 045, see below) |
| Campaign message (`{{2}}`) | Implemented and deployed (Task 046), **not active**: the two-variable template `raslordeck_broadcast_v2` does not exist in Meta yet (read-only check, 2026-10-03). Until it is approved and configured, WhatsApp broadcasts keep the fixed `raslordeck_broadcast` wording. See [WhatsApp campaign message](#whatsapp-campaign-message-task-046). |
| Contacts with WhatsApp broadcast opt-in | 1: the owner's test contact, recorded by staff on 2026-10-02 |
| Contacts with email broadcast opt-in | 1: the same contact, recorded by staff on 2026-10-02 |

Nothing is sent while the switch is off. With the switch on, only contacts with recorded opt-ins are eligible.

### Production template vs UAT template

**Production campaign template (active since Task 045): `raslordeck_broadcast`.** Read-only Meta check, 2026-10-02:

| Property | Value |
| --- | --- |
| Name | `raslordeck_broadcast` |
| Category / status | MARKETING / APPROVED (`rejected_reason: NONE`) |
| Language | `en` |
| Components | BODY only: no header, footer or buttons |
| Variables | exactly one, `{{1}}` = contact name (ADMAN parameter `contact_name`, filled from `Contact.display_name`) |
| ADMAN compatibility | Compatible. `WhatsAppBroadcastTemplate::SUPPORTED_PARAMETERS` is `['contact_name']`, and `problem()` returns null. |

Body text:

```
Hello {{1}},

We’re sharing an update from Raslordeck Limited.

Thank you for staying connected with us.
```

Production configuration (server `.env` only; backup `/home/adman/.env.bak-045-*`, mode 600):

```
WHATSAPP_TEMPLATE_BROADCAST=raslordeck_broadcast
WHATSAPP_TEMPLATE_BROADCAST_LANGUAGE=en
WHATSAPP_TEMPLATE_BROADCAST_ENABLED=true
WHATSAPP_TEMPLATE_BROADCAST_PARAMETERS=contact_name
```

After the change: config cache rebuilt (`adman:www-data` 640, `.env` 600), PHP-FPM reloaded, Horizon restarted. The cached config, the template service and the broadcast preview all resolve to `raslordeck_broadcast` / `en`.

The wording is fixed by the template. Every WhatsApp campaign sends the same text, personalised only with the contact's name. ADMAN supports campaign-specific text since Task 046 (`{{2}}`), but only with a template that has that second variable (`raslordeck_broadcast_v2`, not yet created in Meta).

**UAT template (history): `broadcast_test`.**
- Approved Marketing template with test wording, used only for the Task 041 controlled UAT.
- It stays approved in Meta and was not deleted, but it is no longer configured anywhere in ADMAN (`.env` and cached config have no reference).
- Do not configure it for real campaigns.

`broadcasts_enabled` is **false**, so no WhatsApp or email campaign can currently be sent, whatever template is configured.

**Email campaigns need no template change.** Staff supply the subject and body for each broadcast. The email template adds the greeting `Hello <contact display name>,`, the sign-off, and the unsubscribe footer and headers. There is no placeholder substitution in the body, and no attachments.

### Operational rules

- **Turning `broadcasts_enabled` off does not cancel a broadcast that is already queued or sending.** The switch is only read when previewing and starting. To stop a running broadcast, use **Cancel** on the broadcast page: pending recipients are cancelled, and already-queued messages are blocked by the delivery guard.
- At most 500 recipients. A larger eligible audience is refused outright (never a partial send), and a Selected audience over 500 contacts fails validation.
- Sending needs `broadcasts.send`, a reviewed preview and a confirmed recipient count. If the audience changed since the review, the send is refused.
- Consent:
  - Email needs an explicit email broadcast opt-in and no unsubscribe.
  - WhatsApp needs an explicit WhatsApp broadcast opt-in (separate from the transactional opt-in) and no opt-out.
  - Archived and Unknown contacts are never included. Nobody is opted in automatically.
  - Eligibility is checked again just before each delivery.

## Architecture

```
Broadcast (draft) ──preview──▶ BroadcastEligibilityService (per contact)
     │ owner clicks "Send to N" (broadcasts.send, confirms N)
     ▼
BroadcastService::start ── row lock, all gates re-checked, snapshot ──▶ broadcast_recipients (pending)
     │ dispatch ProcessBroadcastJob (after commit)
     ▼
ProcessBroadcastJob ── batch of 20, then re-dispatch after 10 s ──▶ per recipient (row lock):
     re-check eligibility ─▶ skipped
     WhatsAppOutboundService::queueBroadcastTemplate / EmailOutboundService::queueBroadcastEmail
          └─ normal Message + SendOutbound*Job (existing pipeline, Horizon)
               └─ BroadcastDeliveryGuard (last-moment check) ─▶ provider
Message status changes (send result, Meta status webhooks) ──MessageObserver──▶ BroadcastService::syncRecipientFromMessage
     └─ finalizeIfDone ─▶ completed / failed
```

No second messaging system: every broadcast message is an ordinary `Message` in a normal conversation (Human mode, subject `Broadcast: <name>`), so it appears in the conversation history and uses the existing jobs, adapters, retries policy and audit events (`whatsapp.queued/sent/failed`, `email.queued/sent/failed`).

### Key classes

- `App\Models\Broadcast`, `App\Models\BroadcastRecipient`
- `App\Enums\BroadcastStatus`, `BroadcastRecipientStatus`, `BroadcastAudience`
- `App\Services\BroadcastService` — create/update draft, `preview`, `start`, `processNextBatch`, `cancel`, `stop`, `syncRecipientFromMessage`, `finalizeIfDone`, `counts`
- `App\Services\BroadcastDeliveryGuard` — final check before the provider call
- `App\Services\BroadcastEligibilityService` — single source of truth for who may receive a broadcast (Task 035, extended)
- `App\Jobs\ProcessBroadcastJob` (`ShouldBeUniqueUntilProcessing`, 3 tries, `failed()` stops the broadcast)
- `App\Observers\MessageObserver` (registered on `Message` via `#[ObservedBy]`, runs after commit)
- `App\Support\WhatsAppBroadcastTemplate` — reads and validates the Marketing template config; builds the body parameters (`contact_name`, `broadcast_message`) and checks the broadcast's content fits the template (`problemFor`)
- `App\WhatsApp\WhatsAppTemplateCatalog` — read-only, cached (10 min) lookup of an APPROVED template's body text in WhatsApp Manager, used only for the preview (Task 046)
- `App\Http\Controllers\Broadcasts\BroadcastController`, `BroadcastUnsubscribeController`, `routes/broadcasts.php`
- Vue: `pages/broadcasts/Index|Create|Edit|Show.vue`, `components/broadcasts/BroadcastForm.vue`

### Data model

`broadcasts`: name, channel, audience_type, selected_contact_ids (json), subject/body (email), whatsapp_template_name/language (snapshot at start), whatsapp_message (nullable text, the WhatsApp campaign message for `{{2}}`, Task 046; null for email and for broadcasts created before Task 046), status, recipient_limit / recipient_count / exclusion_summary (snapshot at start), failure_reason, created_by / sent_by / cancelled_by, send_requested_at / started_at / completed_at / cancelled_at / failed_at.

`broadcast_recipients`: broadcast_id, contact_id, communication_identity_id, channel, address, status, message_id (**unique**), provider_message_id, failure_reason, queued/sent/delivered/failed timestamps. **Unique (broadcast_id, contact_id).**

`businesses.broadcasts_enabled` (bool, default false). `contacts.whatsapp_broadcast_opt_in_at` / `_source` (nullable).

## Statuses

Broadcast: `draft` → `queued` (send requested, recipients snapshotted) → `sending` (first batch picked up) → `completed`, or `failed` (stopped, or nobody could be sent), or `cancelled` (from draft, queued or sending).

Recipient: `pending` (snapshotted) → `queued` (Message created) → `sent` (provider accepted) → `delivered` (Meta status webhook delivered/read). Also `failed`, `skipped` (no longer eligible when its turn came) and `cancelled` (broadcast cancelled or stopped first). "Sent" counts include delivered.

## Audiences

| Audience | Candidates | Statuses passed to eligibility |
| --- | --- | --- |
| Customers | all contacts with status Customer (incl. archived) | Customer |
| Customers + Prospects | status Customer or Prospect | Customer, Prospect |
| Selected contacts | the chosen IDs (max 500 in validation) | Customer, Prospect |

Archived and Unknown contacts are never sent to: they are excluded by eligibility (`archived`, `status_not_in_audience`) and show up in the exclusion counts. The selection list in the UI only offers active Customers and Prospects.

## Consent

Eligibility (`BroadcastEligibilityService`), checked three times: preview, when the recipient is queued, and right before the provider call.

- **WhatsApp:** not archived; status in audience; WhatsApp enabled (config + business); valid number; identity active and not linked to another contact; `whatsapp_opt_in` **with** recorded evidence; **`whatsapp_broadcast_opt_in_at` recorded (new in Task 038)**; no `whatsapp_broadcast_opt_out_at`. The transactional opt-in alone is never enough.
- **Email:** not archived; status in audience; email enabled; valid address; `email_broadcast_opt_in_at` recorded; not unsubscribed.

Staff record the WhatsApp broadcast opt-in on the contact form ("Customer agreed to receive WhatsApp broadcasts", with a source). The `unsubscribe_link` source cannot be chosen by staff; only the system sets it. Existing contacts were not opted in by the migration and must not be opted in just to test.

Consent snapshot: `start()` stores the eligible contacts as recipients plus an exclusion summary by reason. A later opt-out still wins: the recipient becomes `skipped` and nothing is sent.

## Sending gates (all server-side, in `start()`, under a row lock)

1. User has `broadcasts.send` (403 otherwise; route middleware + service check).
2. Broadcast is still a draft (so it cannot be sent twice).
3. `businesses.broadcasts_enabled` is true.
4. WhatsApp: a valid Marketing template is configured (see below), and the broadcast's campaign message fits it (present if the template has `broadcast_message`, absent if it has fixed wording). Email: subject and body present, sender address configured.
5. At least one eligible recipient.
6. Eligible count ≤ recipient limit. Otherwise refused outright: no recipients, no messages, no partial send.
7. Eligible count equals the count the user confirmed on the review screen (`confirm_recipient_count`); if the audience changed, the user must review again.

Creating or editing a draft never sends anything.

## Recipient limit

`adman.broadcasts.recipient_limit` (`BROADCAST_RECIPIENT_LIMIT`, default 500). `BroadcastService::recipientLimit()` clamps it to 1–500, so configuration can lower but never raise the hard limit. There is no bypass.

## Queue processing

- `ProcessBroadcastJob` handles `adman.broadcasts.batch_size` recipients (default 20), then re-dispatches itself after `batch_delay_seconds` (default 10). A 500-recipient broadcast takes roughly 4–5 minutes and never monopolises the single Horizon worker; transactional jobs interleave.
- Dispatches use `->afterCommit()` (the queue connection has `after_commit = false`).
- If the job fails 3 times, `failed()` stops the broadcast (`failed`, pending recipients cancelled).

## Idempotency (MySQL is the source of truth)

- One recipient row per (broadcast, contact) — unique index.
- A recipient is claimed with `SELECT … FOR UPDATE` and must be `pending`; it moves to `queued` together with its single Message in the same transaction. Re-running the job finds nothing pending.
- `broadcast_recipients.message_id` is unique.
- `BroadcastDeliveryGuard` allows the provider call only when the recipient is exactly `queued` and the message is that recipient's message.
- Broadcast messages are **never resent**: provider failures are recorded as non-retryable, staff retry is refused (`retry()` on both services), and a job retry after an unexpected error is blocked by the guard. A provider timeout may still have delivered, and a duplicate marketing message is worse than a gap.

Redis/Horizon uniqueness locks are only an optimisation.

## Failure handling

- One recipient failing (no WhatsApp account, opt-out at Meta, invalid email, provider rejection) marks that recipient `failed` and the broadcast continues.
- **Account-level** WhatsApp failures stop the whole broadcast (`WhatsAppErrorMapper::isAccountLevelReason`): template missing (132001), parameter mismatch (132000/132012), template paused/disabled (132015/132016), payment issue (131042), account locked (131031/368), quality restriction (131048), authentication (190/401/403), phone number config (404). The broadcast becomes `failed`, remaining pending recipients are `cancelled`, and messages already sent stay recorded.
- Changing or disabling the template config mid-broadcast stops it at the next batch.
- If every attempted recipient failed and none were sent, the broadcast ends `failed`.

## Cancellation

`broadcasts.manage` users can cancel a draft, queued or sending broadcast. Pending recipients become `cancelled`; already-queued messages are blocked by the guard; sent messages stay recorded. Audited (`broadcast.cancelled`, with the number of recipients cancelled). Completed, failed or cancelled broadcasts cannot be cancelled.

## WhatsApp Marketing template requirement

```
WHATSAPP_TEMPLATE_BROADCAST=<exact approved template name>
WHATSAPP_TEMPLATE_BROADCAST_LANGUAGE=<exact language code, e.g. en>
WHATSAPP_TEMPLATE_BROADCAST_ENABLED=true
WHATSAPP_TEMPLATE_BROADCAST_PARAMETERS=        # empty = no variables, contact_name, or contact_name,broadcast_message
```

`WhatsAppBroadcastTemplate::configured()` returns null (and `problem()` explains why) when disabled, no name, the name equals any configured transactional template (`quote_document`, `invoice_sent`, `invoice_reminder`, `payment_acknowledgement`, case-insensitive), a parameter other than `contact_name` / `broadcast_message` is listed, or a parameter is listed twice. There is no fallback to a Utility template.

ADMAN cannot read the template category at send time; setting `ENABLED=true` is the operator's statement that WhatsApp Manager shows the template as **Approved** with category **Marketing**. Never set it for a template that is pending, rejected, or Utility.

The send has no header and, when `PARAMETERS` is empty, no body component (adapter change: the body component is now only sent when there are parameters; transactional templates always have parameters, so they are unchanged). WhatsApp Marketing messages are charged per message by Meta and may be limited per customer (131049).

## WhatsApp campaign message (Task 046)

A WhatsApp broadcast can carry campaign-specific text in a second template variable:

| Variable | ADMAN parameter | Source |
| --- | --- | --- |
| `{{1}}` | `contact_name` | `Contact.display_name` of each recipient (personalised) |
| `{{2}}` | `broadcast_message` | `broadcasts.whatsapp_message`: one value per broadcast, identical for every recipient |

**Status: implemented and deployed, not active.** Production still uses `raslordeck_broadcast` (`contact_name` only), which has fixed wording, so the Message field is not shown and a campaign message is refused. The feature becomes active only when the owner creates the template below, Meta approves it, and the configuration is switched (see Activation).

### Template (`raslordeck_broadcast_v2`, to be created in WhatsApp Manager)

Marketing, English (`en`), body only (no header, footer or buttons). Do not edit or delete `raslordeck_broadcast`.

```
Hello {{1}},

We’re sharing an update from Raslordeck Limited.

{{2}}

Thank you for staying connected with us.
```

Sample values for Meta review: `{{1}}` = a contact name, `{{2}}` = "We have an important update to share with you."

Read-only Meta check on 2026-10-03: `raslordeck_broadcast_v2` **does not exist** (neither pending nor approved). It was therefore not configured.

### Behaviour

- **Form:** when the configured template has `broadcast_message`, the WhatsApp section of the create/edit form shows a required plain-text **Message** field with a character counter (max **700**), and helper text explaining that the message is inserted into the approved template, the customer name is added automatically, and the same message goes to every recipient. No rich text, HTML, attachments or variables.
- **Validation (server, `StoreBroadcastRequest`):**
  - Template uses `broadcast_message`: `whatsapp_message` is required, a string, at most 700 characters (Meta caps a template body at 1024 characters; the rest is left for the fixed wording and the name), and must not contain `{{` or `}}`.
  - Template has fixed wording: `whatsapp_message` is prohibited.
  - No valid template: optional (the draft can be saved, but the template blocker prevents sending).
  - The form checks the length and `{{`/`}}` on the client too. Whitespace-only input is rejected.
- **Storage:** trimmed, line endings normalised to `\n`, saved on the broadcast. Email broadcasts always store null. Existing broadcasts (#1, #2) keep null and stay readable.
- **Parameters:** `WhatsAppBroadcastTemplate::bodyParametersFor()` builds them server-side at queue time from the persisted Broadcast and Contact: exactly `[contact_name, broadcast_message]` (in the configured order). Nothing from the request reaches the parameters: extra fields such as `body_parameters` are ignored, and the send request only accepts `confirm_recipient_count`.
- **Line breaks:** Meta rejects line breaks, tabs and long runs of spaces inside template variables, so each value is collapsed to single spaces (the same rule the delivery step already applied). Punctuation and Unicode are kept. The preview shows the collapsed text and says so.
- **Conversation record:** the broadcast's `Message.body` adds `Campaign message: <text>` so staff can see what was sent; `meta.body_parameters` stores the exact values.
- **Preview (Show page, drafts):** the message as received by a sample recipient (the first eligible contact, or "Customer name" if none), labelled as personalised name vs shared campaign message, next to the unchanged recipient count, exclusions and blockers.
  - The fixed wording is the template's **approved** body text, read from WhatsApp Manager with `WhatsAppTemplateCatalog` (read-only GET, 5 s timeout, cached 10 minutes).
  - If it cannot be read, the page lists the values that will be inserted instead.
  - The lookup is display-only: it is not part of `preview()`/`start()` and never decides whether or what to send.
- **Mismatch guard:** `WhatsAppBroadcastTemplate::problemFor()` blocks preview and start when the template needs a message and the draft has none, or the draft has a message but the template has fixed wording (it would be silently dropped). `processNextBatch` re-checks it, and stops the broadcast if the configuration changed mid-send.
- **Editing:** unchanged lifecycle. Only drafts can be edited (edit page redirects, `update()` refuses under a row lock), so the campaign message cannot change once a broadcast is queued, sending or finished.
- **Unchanged:** consent and eligibility, the 500 cap, `broadcasts.send`, the business switch, preview and confirmed count, cancellation, idempotency, batching, account-level failure handling, email broadcasts, and transactional templates (a Utility template name is still refused as the broadcast template, with any parameters).

### Activation (owner, only after Meta shows `raslordeck_broadcast_v2` as Approved)

1. Read-only Meta check: Approved, Marketing, `en`, body only, exactly `{{1}}` and `{{2}}`, wording as above.
2. Server `.env` (backup first, mode 600):
   ```
   WHATSAPP_TEMPLATE_BROADCAST=raslordeck_broadcast_v2
   WHATSAPP_TEMPLATE_BROADCAST_LANGUAGE=en
   WHATSAPP_TEMPLATE_BROADCAST_ENABLED=true
   WHATSAPP_TEMPLATE_BROADCAST_PARAMETERS=contact_name,broadcast_message
   ```
3. Rebuild config cache (`adman:www-data` 640), reload PHP-FPM, restart Horizon. Verify the cached config and that a draft's preview renders both variables.
4. Keep `broadcasts_enabled=false` until a campaign is deliberately sent. Old drafts without a message are blocked until a message is added.

### UAT Marketing template (Task 041, historical; replaced by `raslordeck_broadcast` in Task 045)

Read-only Meta check on 2026-10-02 (HTTP 200): at that time `broadcast_test` was the only MARKETING template.

| Property | Value |
| --- | --- |
| Name | `broadcast_test` |
| Category / status | MARKETING / APPROVED (`rejected_reason: NONE`) |
| Language | `en` |
| Components | BODY only: no header, footer or buttons |
| Variables | exactly one, `{{1}}` = contact name (ADMAN parameter `contact_name`, filled from `Contact.display_name`) |

Body text:

```
Hello {{1}},

This is a test broadcast message from Raslordeck Limited.

No action is required. This message is being sent as part of a controlled WhatsApp broadcast test.
```

UAT configuration used in Task 041 (server `.env` only, backup `/home/adman/.env.bak-041-*`, mode 600; replaced in Task 045):

```
WHATSAPP_TEMPLATE_BROADCAST=broadcast_test
WHATSAPP_TEMPLATE_BROADCAST_LANGUAGE=en
WHATSAPP_TEMPLATE_BROADCAST_ENABLED=true
WHATSAPP_TEMPLATE_BROADCAST_PARAMETERS=contact_name
```

After the change: config cache rebuilt (`adman:www-data` 640), PHP-FPM reloaded, Horizon restarted. `WhatsAppBroadcastTemplate::problem()` returns null. The transactional templates are unchanged.

`broadcast_test` is a UAT template and is no longer configured; see Production template vs UAT template.

### Production UAT (Task 041) — PASS

Broadcast #1, "Task 041 WhatsApp broadcast UAT":
- WhatsApp, Selected contacts, the owner's test contact only.
- Preview: 1 eligible, 0 excluded, template `broadcast_test` / `en`. Sent by the owner account through `BroadcastService` (the same path as the Send button).

| Item | Result |
| --- | --- |
| Broadcast | `completed`: send requested 15:43:38, started 15:43:40, completed 15:43:42 WAT. Snapshot `broadcast_test` / `en`, 1 recipient. |
| Recipient #1 | contact #2, identity #7 (canonical), `delivered`: queued 15:43:41, sent 15:43:42, delivered 15:43:50 |
| Message #129 | outbound, `delivery_kind = broadcast_template`, `template_name = broadcast_test`, `template_language = en`, body parameter = the contact's display name. One wamid stored. Status `delivered`. |
| Audits | `broadcast.created`, `send_requested`, `started`, `recipient_queued`, `recipient_sent`, `completed`; `whatsapp.queued` and `whatsapp.sent`; the two `business.settings_updated` switch changes |
| Safety | 0 duplicate provider IDs, 0 duplicate recipients, 0 stuck recipients or messages, failed_jobs 0. Invoices, payments, claims, quote and reminder data unchanged. |

**Conversation behaviour:** the broadcast message is stored in the contact's existing open WhatsApp conversation (#7), because `openConversation` reuses any non-closed conversation for the identity. The conversation mode (`human`) and assignee did not change; only `last_message_at` moved. An outbound broadcast does not open the 24-hour window, which only counts inbound messages.

### Email broadcast UAT (Task 042, 2026-10-02) — PASS

Broadcast #2, "Task 042 Email broadcast UAT":
- Email, Selected contacts, the owner's test contact only.
- Subject `ADMAN Email Broadcast UAT`.
- Preview: 1 eligible, 0 excluded. Sent by the owner account through `BroadcastService`.

The template adds the greeting `Hello <contact display name>,` itself; message bodies have no placeholder syntax. So the body was only the two approved paragraphs, which gave the intended "Hello Taribi Isaac," greeting. Before sending, the email was rendered on production without sending:
- correct subject;
- 0 attachments;
- greeting with the contact name, then the two paragraphs;
- the standard "Thanks, Raslordeck Limited" sign-off;
- the unsubscribe footer;
- `List-Unsubscribe` and `List-Unsubscribe-Post` headers.

| Item | Result |
| --- | --- |
| Broadcast | `completed`: send requested 16:45:56, started 16:45:57, completed 16:45:58 WAT. 1 recipient. |
| Recipient #2 | contact #2, email identity #8, `sent`: queued 16:45:57, sent 16:45:58 |
| Message #130 | email, outbound, `template_key = broadcast`, no document, subject and body as approved. Stored in the contact's existing email conversation (#8). Status `sent`. |
| Resend | accepted, then `last_event = delivered` (read-only Resend API check). Exactly one email with this subject. |
| Audits | `broadcast.created`, `send_requested`, `started`, `recipient_queued`, `recipient_sent`, `completed`; `email.queued` and `email.sent`; the two `business.settings_updated` switch changes |
| Safety | 0 duplicate provider IDs or recipients; nothing stuck; failed_jobs 0. No WhatsApp message. Invoices, payments, claims, quote, reminders and conversation #7 unchanged. Unsubscribe not clicked; consent unchanged. |

**Provider ID:** ADMAN stores `laravel-mail-<message id>` for every email, transactional and broadcast alike. The adapter only records an ID when the transport returns a `Message-ID` header, and Resend's ID is not returned. The real Resend email ID can be found in the Resend dashboard or API by subject and time. With no Resend webhooks, ADMAN email recipients stop at `Sent`.

## Email unsubscribe

- Every broadcast email has a signed, login-free link: `GET /email/unsubscribe/{contact}/{broadcast}?signature=…` (`URL::signedRoute('broadcasts.unsubscribe')`). It shows a confirmation page; the button POSTs to the same signed URL.
- POST records `email_broadcast_unsubscribed_at = now()` and source `unsubscribe_link` (idempotent), audited as `contact.consent_changed` with `broadcast_id` in meta.
- Headers: `List-Unsubscribe: <signed URL>` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058). The path is exempt from CSRF because mail clients POST without a session; the signature protects it. Throttled to 30/min.
- Unsubscribing only stops broadcasts. Quotes, invoices, reminders and receipts are unaffected; transactional emails have no unsubscribe link or header (unchanged).

## Permissions

| Permission | Allows |
| --- | --- |
| `broadcasts.manage` | List, create, edit drafts, view results, cancel |
| `broadcasts.send` | Start sending (also needs `manage` to reach the page) |

Created by migration `2026_10_02_120000_add_broadcast_permissions` and granted to Super Administrator (who also bypasses checks via `Gate::before`). The Staff role does **not** get them. Grant `broadcasts.send` to anyone else only deliberately. The sidebar entry appears only with `broadcasts.manage`.

## Audit events

`broadcast.created`, `broadcast.updated`, `broadcast.send_requested`, `broadcast.started`, `broadcast.recipient_queued`, `broadcast.recipient_sent`, `broadcast.recipient_failed`, `broadcast.cancelled`, `broadcast.completed`, `broadcast.failed`. Per-recipient events are one per recipient per transition (at most about 3 per recipient), never per batch tick or status poll; skipped recipients are not individually audited (the reason is on the recipient row).

## Financial isolation

Broadcasts never read or write invoices, payments, claims, quotes, reminder rules or occurrences (covered by `test_broadcasts_do_not_touch_financial_or_reminder_records`). Transactional WhatsApp/email behaviour, the 24-hour window logic, reminders and AI handoff are unchanged.

## Enablement procedure (owner)

1. **Meta:** in WhatsApp Manager create a template with category **Marketing** and the exact wording. Wait for **Approved**. Do not reuse a Utility template.
2. **Server env:** set the four `WHATSAPP_TEMPLATE_BROADCAST*` values (exact name and language from Meta), then deploy or `php artisan config:cache` and restart Horizon through the normal process.
3. **Consent:** for each customer who explicitly agreed, record the WhatsApp broadcast opt-in and/or email broadcast opt-in on the contact with the real source. Never bulk-opt-in.
4. **Switch:** Business settings → tick "Broadcasts enabled".
5. **Send:** Broadcasts → New → save draft → review the counts, exclusions, template/subject and message → tick the confirmation → "Send to N".
6. Watch the detail page (Refresh progress). Untick "Broadcasts enabled" afterwards if broadcasts should stay off.

## UAT procedure (when the prerequisites exist)

1. Use only the owner's own contact(s), with genuine broadcast consent recorded by the owner.
2. Email: create a "Selected contacts" broadcast with only the owner's contact; confirm count 1; send. Expect the email (no attachment) with an unsubscribe link and the recipient row `Sent`. Click unsubscribe → confirmation page → contact shows "Unsubscribed"; a new draft shows 0 eligible.
3. WhatsApp (only after a Marketing template is Approved and configured): same with the owner's WhatsApp contact; expect the template on the phone, recipient `Sent` then `Delivered`, and a `wamid` stored. **Done in Task 041: PASS** (see Production UAT above). Email send done in Task 042: **PASS** (see Email broadcast UAT; the unsubscribe click was not repeated in production).
4. Check no customer conversation besides the owner's received anything, and invoices/payments are unchanged.

## Limitations / deferred

- No scheduling, segmentation beyond the three audiences, templates with header media or buttons, template variables beyond `contact_name` (per recipient) and `broadcast_message` (per broadcast), or resend of failed recipients.
- WhatsApp template category/approval is not verified via the API at send time (operator assertion).
- Turning `broadcasts_enabled` off blocks new sends (preview and start) but does not stop a broadcast that is already queued or sending. Use Cancel for that.
- Email delivery status beyond "accepted by provider" (no Resend webhooks), so email recipients stop at `Sent`. The Resend email ID is not captured; ADMAN stores `laravel-mail-<message id>`.
- Email bodies have no placeholder substitution: the template's greeting is the only personalised part.
- No automatic opt-out from WhatsApp replies such as "STOP"; staff record opt-outs on the contact.
- Unsubscribe link targets a contact, so contacts sharing one address are unsubscribed individually.
