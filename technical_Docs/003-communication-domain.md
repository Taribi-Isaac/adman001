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
| `actor_type` | `external` \| `staff` \| `system` |
| `actor_user_id` | Staff user when applicable |
| `external_message_id` | Future provider reference |
| `occurred_at` | Sent/received time |

Actor modeling is a simple enum + optional `actor_user_id`. There is **no** artificial AI user account. Future AI-authored messages can use `system` (or a dedicated enum value added in the AI task) without inventing a fake user.

Messages and conversations are **not hard-deleted** by this domain. Contact archive does not cascade-delete communication history.

## Message status semantics

| Status | Meaning |
| --- | --- |
| `recorded` | Stored in ADMAN history only — **not** externally delivered |
| `pending` | Reserved for future provider handoff |
| `sent` | Reserved for provider-confirmed send |
| `delivered` | Reserved for provider-confirmed delivery |
| `failed` | Reserved for provider-confirmed failure |

Internal compose and seed inbound records created in this task always use `recorded`. The UI labels them as **Internal record**. Do not present fake WhatsApp/email delivery.

## AI / Human / Closed modes

| Mode | Meaning |
| --- | --- |
| **AI** | Eligible for AI handling when business AI customer responses are enabled (Task 010). |
| **Human** | Staff controls the conversation. Future AI must not reply. |
| **Closed** | Closed; no automated processing. History preserved. |

### Human takeover

- Sets mode to Human, assigns current staff user, audits `conversation.taken_over`.
- Deliberate; composing a message does **not** auto-takeover beyond existing mode rules.
- Returning to AI sets mode to AI, clears assignment, audits `conversation.returned_to_ai`, and does **not** generate an AI response.

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
| `messages.compose` | Compose internal outbound `recorded` messages |

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
| Automated reminders / broadcasts | Automation / later product tasks |
| Fake delivery simulation | Never — only real provider results |

## UI notes

- Shell nav: **Conversations** enabled.
- List supports search (name/identifier/contact), mode, and channel filters.
- Detail layout: message thread + context panel (identity, contact / unknown, controls).
- Mode is shown with text label (not color alone).
- Composer clearly states internal recording only.

## Key modules

- `app/Services/ConversationService.php`
- `app/Http/Controllers/Conversations/ConversationController.php`
- `app/Models/{CommunicationIdentity,Conversation,Message}.php`
- `routes/conversations.php`
- `resources/js/pages/conversations/*`
- `tests/Feature/CommunicationTest.php`
