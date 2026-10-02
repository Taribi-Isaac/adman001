# ADMAN Technical Documentation — Communication Domain

## Purpose

The Communication domain is the provider-neutral foundation for business messaging in ADMAN.

It establishes:

```text
Communication Identity → Conversation → Messages
```

with optional business context:

```text
Communication Identity → Contact (optional)
```

and conversation control modes:

```text
AI ↔ Human → Closed
```

This task does **not** integrate WhatsApp Cloud API, email providers, or AI response generation.

## Contact vs Communication Identity

| Concept | Role |
| --- | --- |
| **Contact** | Canonical business identity (Unknown → Prospect → Customer). Owned by the Contacts domain. |
| **Communication Identity** | External channel address/person (WhatsApp ID, email address, etc.). May exist without a Contact. |

A person can message the business before becoming a Prospect or Customer. Communication must not redefine or replace Contact.

## Communication identity lifecycle

Table: `communication_identities`

| Column | Notes |
| --- | --- |
| `channel` | `whatsapp` \| `email` (extensible string enum) |
| `external_id` | Provider/channel address (unique per channel) |
| `display_name` | Optional channel-supplied name |
| `contact_id` | Optional FK to `contacts` (`nullOnDelete`) |
| `is_active` | Soft activity flag |

Rules:

- Unknown identities are valid without `contact_id`.
- Staff may **link** / **unlink** an identity to a Contact.
- Linking **does not** change Contact lifecycle status.
- No automatic merge or auto-promotion.

## Conversation model

Table: `conversations`

A conversation is a business communication thread for one communication identity (not a group chat room).

| Column | Notes |
| --- | --- |
| `communication_identity_id` | Required; `restrictOnDelete` |
| `contact_id` | Denormalized from identity when linked; `nullOnDelete` |
| `channel` | Mirrors identity channel |
| `mode` | `ai` \| `human` \| `closed` |
| `assigned_user_id` | Staff owner when Human |
| `subject` | Optional |
| `last_message_at` | Activity ordering |
| `closed_at` | Set when Closed |

Opening a conversation reuses an existing non-closed thread for the same identity when present.

## Message model

Table: `messages`

| Column | Notes |
| --- | --- |
| `conversation_id` | Required; `restrictOnDelete` |
| `direction` | `inbound` \| `outbound` |
| `channel` | Channel at time of message |
| `body` | Message content |
| `status` | See status semantics below |
| `actor_type` | `external` \| `staff` \| `system` \| `ai` |
| `actor_user_id` | Staff user when applicable |
| `external_message_id` | Future provider reference |
| `occurred_at` | Sent/received time |

Actor modeling is a simple enum + optional `actor_user_id`. There is **no** artificial AI user account. Future AI-authored messages can use `system` (or a dedicated enum value added in the AI task) without inventing a fake user.

Messages and conversations are **not hard-deleted** by this domain. Contact archive does not cascade-delete communication history.

## Message status semantics

| Status | Meaning |
| --- | --- |
| `recorded` | Stored in ADMAN history only — **not** externally delivered (internal notes, seed records) |
| `pending` | Queued for provider delivery (UI: Queued) |
| `processing` | Delivery job is currently sending |
| `sent` | Provider accepted the message (provider message id stored) |
| `delivered` / `read` | Provider status webhooks (forward-only) |
| `failed` | Provider rejected or delivery failed; `failure_reason` shown to staff |

Internal notes always use `recorded` and are labelled **Internal record**. Do not present fake WhatsApp/email delivery.

## AI / Human / Closed modes

| Mode | Meaning |
| --- | --- |
| **AI** | Eligible for AI handling when business AI customer responses are enabled (Task 010). |
| **Human** | Staff controls the conversation. AI does not reply. |
| **Closed** | Closed; no automated processing. History preserved. |

### Human attention vs human takeover (no extra state)

| Condition | Meaning |
| --- | --- |
| Human + no assigned user (not closed) | **Human attention required** — typically after AI handoff; nobody has claimed it |
| Human + assigned user | **Human takeover** — a staff member owns it |

`Conversation::needsHumanAttention()` / `scopeNeedsHumanAttention()` encode the first row. It drives the dashboard card **Human attention required**, the Conversations list filter **Needs attention only** (`?attention=1`), and the badge on the thread page. The thread page also shows the **handoff reason** from the latest `conversation.escalated_to_human` audit event while that human phase is current (not stored elsewhere).

### Human takeover

- **Take over** is offered when the user has `conversations.takeover`, the conversation is not closed, and it is either in AI mode or in Human mode with nobody assigned (AI-escalated). It is not offered on conversations already owned by a staff member, and since Task 033 the service enforces the same rule: taking over a Human conversation assigned to someone else is rejected ("already being handled by …"), atomically, so two stale clicks cannot swap ownership. Taking over your own conversation again is a no-op. There is no reassignment workflow; the only way to move an owned conversation is **Return to AI** (any user with `conversations.takeover`, audited) followed by a new Take over.
- Sets mode to Human, assigns current staff user, audits `conversation.taken_over`.
- Deliberate; composing a message does **not** auto-takeover beyond existing mode rules.
- Returning to AI sets mode to AI, clears assignment, audits `conversation.returned_to_ai`, and does **not** generate an AI response.

### Staff WhatsApp reply (Task 032)

The thread composer has two tabs: **WhatsApp reply** (sent to the customer) and **Internal note** (`recorded`, staff only).

A WhatsApp reply is allowed only when all of these hold (checked server-side by `WhatsAppOutboundService::staffReplyBlocker`; the UI shows the same reason):

1. WhatsApp conversation, user has `messages.send`, outbound WhatsApp enabled
2. Conversation open and in **Human** mode
3. Assigned to the **current** user (take over first; another staff member's conversation cannot be replied to — prevents two people answering at once)
4. Valid, active WhatsApp identity
5. Meta 24-hour customer service window open (see `008-whatsapp-communication.md`)

`POST /conversations/{id}/whatsapp-reply` → `WhatsAppOutboundService::queueStaffSessionReply` → outbound message (`actor_type = staff`, `actor_user_id`, `status = pending`, `meta.delivery_kind = session_text`) → existing `SendOutboundWhatsAppJob` → Cloud API text message. Staff text is sent as written (no AI formatter). Status then follows `pending → processing → sent → delivered/read` or `failed`; the thread refreshes while a message is queued. Audit: `whatsapp.staff_reply_queued` (actor = staff) plus the existing `whatsapp.sent` / `whatsapp.failed` (provider message id).

Replying does **not** change mode or assignment, does not create AI processing, and does not notify; the conversation stays Human until someone explicitly returns it to AI.

### Close / reopen

- Close → mode Closed + `closed_at`, audit `conversation.closed`.
- Reopen → Human mode by default, clears `closed_at`, audit `conversation.reopened`.

## Contact linking rules

1. Staff searches/selects an existing Contact.
2. Identity and open conversations receive `contact_id`.
3. Contact lifecycle status is unchanged.
4. Promotion remains a Contacts-domain action.

## Authorization

| Permission | Purpose |
| --- | --- |
| `conversations.view` | List/show conversations |
| `conversations.manage` | Create conversations / identities for testing |
| `conversations.takeover` | Take over / return to AI |
| `conversations.close` | Close / reopen |
| `conversations.link_contact` | Link / unlink identity ↔ Contact |
| `messages.view` | View message history (required on show) |
| `messages.compose` | Internal notes (`recorded` messages) |
| `messages.send` | Staff WhatsApp replies (plus document sends) |

All checks are enforced server-side (middleware + `authorize` / FormRequest). Staff role receives these permissions by default.

## Audit behavior

Audited control actions:

- `conversation.created`
- `communication_identity.linked` / `unlinked`
- `conversation.taken_over`
- `conversation.returned_to_ai`
- `conversation.closed`
- `conversation.reopened`

Ordinary messages are **not** audited as heavyweight events; the message table is the communication history.

## Provider-neutral design

Channels are enum values on shared tables. Future WhatsApp and email adapters can:

- upsert communication identities by `(channel, external_id)`
- open/reuse conversations
- record inbound messages with provider IDs
- update outbound status from `pending` → `sent` / `delivered` / `failed`

This domain does **not** include:

- generic plugin/driver frameworks
- transport buses
- event-sourcing orchestration
- provider credentials or webhooks

## Deliberately deferred

| Topic | Deferred to |
| --- | --- |
| WhatsApp Cloud API / Meta webhooks / templates | [008 — WhatsApp communication](008-whatsapp-communication.md) |
| Email provider transport details | [007 — Email delivery](007-email-delivery.md) (Laravel Mail adapter) |
| Inbound email sync | Future |
| AI responses / agents / tools | AI task |
| Automated reminders / broadcasts | Reminders: `009-invoice-reminders.md`. Broadcasts (Task 038): `017-broadcasts.md` — each broadcast message is a normal outbound `Message` in a Human-mode conversation (subject `Broadcast: <name>`, `meta.broadcast_id` / `broadcast_recipient_id`); `MessageObserver` mirrors its status onto `broadcast_recipients` |
| Fake delivery simulation | Never — only real provider results |

## UI notes

- Shell nav: **Conversations** enabled.
- List supports search (name/identifier/contact), mode, and channel filters.
- Detail layout: message thread + context panel (identity, contact / unknown, controls).
- Mode is shown with text label (not color alone).
- Composer tabs distinguish **WhatsApp reply** (sent to the customer) from **Internal note** (staff only); an unavailable reply shows the blocking reason.

## Key modules

- `app/Services/ConversationService.php`
- `app/Http/Controllers/Conversations/ConversationController.php`
- `app/Models/{CommunicationIdentity,Conversation,Message}.php`
- `routes/conversations.php`
- `resources/js/pages/conversations/*`
- `tests/Feature/CommunicationTest.php`
