# 010 — AI + Human Handoff

## Purpose

ADMAN’s AI is a **controlled assistant** for customer conversations. Laravel domain services remain the source of truth for contacts, invoices, payments, claims, documents, communication, reminders, RBAC, and audit.

```text
Inbound Message → Conversation → AI eligibility → AiService
  → Authorized context / controlled tools → Existing Laravel services
  → AI response Message → WhatsApp session delivery → Audit
```

The model may **request** tools. Laravel **decides** whether they execute and performs all business mutations.

---

## Architecture

```text
WhatsApp webhook
      ↓
WhatsAppInboundService (record Message)
      ↓
ProcessInboundAiMessage (unique job)
      ↓
AiService
      ↓
AiProvider (OpenAI-compatible | Fake)
      ↓
AiToolRegistry → existing PaymentService / DocumentService / ConversationService / …
      ↓
WhatsAppOutboundService::queueAiSessionReply
      ↓
WhatsAppDeliveryAdapter::sendText
```

Provider abstraction:

- `App\Contracts\AiProvider`
- `App\Ai\Providers\OpenAiCompatibleProvider`
- `App\Ai\Providers\FakeAiProvider` (tests / local)

---

## Eligibility

AI customer auto-response requires **all** of:

1. `config('adman.ai.enabled')`
2. `Business.ai_enabled`
3. `Business.ai_customer_responses_enabled`
4. Conversation `mode = ai`
5. Inbound WhatsApp message
6. Idempotent processing claim (`ai_message_processings`)

Human / Closed mode → processing skipped (no automatic reply).

When customer responses are enabled, **new** inbound WhatsApp conversations open in **AI** mode; otherwise they open in **Human** (previous default).

---

## Conversation modes

| Mode | AI auto-reply |
|------|----------------|
| AI | Yes (if enabled) |
| Human | No |
| Closed | No |

Staff **Take over** → Human (existing).  
Staff **Return to AI** → AI mode; does not generate a response by itself.  
AI **request_human_handoff** / explicit human request → Human mode via `ConversationService::escalateToHuman` (unassigned).

AI cannot change conversation mode except through the handoff tool.

---

## Controlled tools

Read tools: business info, customer context, invoices, invoice detail/status/payments, quotes, recurring billing, payment acknowledgement, secure document link.

Action tools:

- `create_payment_claim` → `PaymentService::createClaim` (always pending verification)
- `request_human_handoff` → escalate to Human

Authorization is server-side (`AiAuthorization`): unknown / unlinked / Unknown-status contacts cannot access customer-specific data.

Financial ambiguity: multiple outstanding invoices without `invoice_number` → clarification required; no guessing.

---

## WhatsApp integration

- Inbound: existing webhook → message record → queue AI job
- Outbound AI replies: **session text** via extended `WhatsAppDeliveryAdapter::sendText` (not templates)
- Document/template sends remain on existing template path; AI must not use those methods
- Opt-in still required for template document sends; session replies use the open conversation identity after inbound

Inbound email AI replies are **deferred** (no inbound email pipeline yet).

---

## Configuration

### Environment

| Variable | Purpose |
|----------|---------|
| `ADMAN_AI_ENABLED` | Global kill switch |
| `ADMAN_AI_PROVIDER` | `openai` or `fake` |
| `ADMAN_AI_API_KEY` | Provider API key |
| `ADMAN_AI_BASE_URL` | Chat completions base URL |
| `ADMAN_AI_MODEL` | Model name |
| `ADMAN_AI_TIMEOUT` | HTTP timeout seconds |

### Business settings

**Settings → AI**

- AI enabled
- AI customer responses enabled

Provider secrets stay in env — not in Business settings.

---

## Idempotency

- Unique `ai_message_processings.inbound_message_id`
- Unique job `ProcessInboundAiMessage`
- Duplicate webhook message IDs still prevented by existing Message uniqueness
- Payment claims rely on PaymentService rules (not a parallel claim system)

Provider failure → processing `failed`, **no** fabricated customer reply, conversation left for humans.

---

## Permissions

- `ai.view` — view AI settings
- `ai.manage` — update AI settings
- `ai.use` — reserved for future staff AI assists

Conversation takeover/return still uses `conversations.takeover`.

---

## Audit

Examples: `ai.enabled` / `ai.disabled`, `ai.response_generated`, `ai.tool_called`, `ai.tool_denied`, `ai.handoff_requested`, `ai.payment_claim_created`, `ai.processing_failed`.

Prompt/response bodies are not stored in audit.

---

## Security

- No unrestricted DB access for the model
- Tool inputs validated; outputs controlled
- No secrets in prompts/responses
- Cross-customer data denied by contact scoping
- Treat model output as untrusted; business mutations only via tools

---

## Testing

`tests/Feature/AiHumanHandoffTest.php` covers settings permissions, mode gating, authorization, payment-claim safety, handoff, idempotency, provider failure, job dispatch.

Automated tests bind `FakeAiProvider` (no external API).

---

## Business knowledge (Task 015)

Editable, non-RAG context is assembled by `AiBusinessContextAssembler` from:

- Business profile fields (`description`, contact details, payment instructions, `ai_support_instructions`)
- Active `business_offerings`
- Active `business_knowledge_articles` (FAQ / policy / support / general)

Managed at `/settings/knowledge`. Inactive rows are excluded. Customer-specific financial data remains tool-only.

Unknown contacts may receive general business knowledge; they are not auto-promoted to customers.

Inbound WhatsApp files are stored privately and acknowledged truthfully (team will review); the AI must not claim to have reviewed file contents.

---

## Limitations / deferred

- No inbound email AI replies
- No RAG / vector knowledge base (structured knowledge is Task 015)
- No multi-provider orchestration platform
- No autonomous financial confirmation
- No staff “ask AI” compose assistant yet (`ai.use`)
- Session WhatsApp replies require Meta’s messaging window rules in production
- OCR / media understanding deferred

---

## External setup

1. Obtain an OpenAI API key (Chat Completions compatible). Never commit it.
2. On the server `.env` only:

```env
ADMAN_AI_ENABLED=true
ADMAN_AI_PROVIDER=openai
ADMAN_AI_API_KEY=<server secret>
ADMAN_AI_BASE_URL=https://api.openai.com/v1
ADMAN_AI_MODEL=gpt-4o-mini
ADMAN_AI_TIMEOUT=45
```

3. `umask 002 && php artisan config:cache` (ensure `bootstrap/cache/config.php` is `adman:www-data` `664`) then `sudo systemctl restart adman-horizon`
4. Enable org switches in Settings → AI (`ai_enabled`, `ai_customer_responses_enabled`)
5. WhatsApp Cloud API must be available for inbound session messages (AI auto-reply is WhatsApp-inbound only today)
6. Confirm `ADMAN_AI_PROVIDER` is never `fake` in production (binding falls through to OpenAI-compatible provider; production-check blocks `fake`)

### Task 024 / 024B status (2026-09-25)

| Item | State |
|------|--------|
| Architecture | Unchanged (Task 010) — OpenAI-compatible Chat Completions via Laravel HTTP |
| Model | `gpt-4o-mini` (existing default — preserved) |
| Provider binding | Production binds `OpenAiCompatibleProvider`; `fake` forbidden |
| Missing/invalid key | Provider returns safe failure — **no fabricated customer replies**; no financial mutations |
| `ADMAN_AI_API_KEY` | **present: yes** (server `.env` only; never Git) |
| `ADMAN_AI_ENABLED` | **true** |
| Business AI flags | `ai_enabled` + `ai_customer_responses_enabled` **true** |
| Live provider test | Succeeded (~1.3–3.5 s) |
| Controlled verification | Business context, authorization denial, payment **claim** (not confirmation), human handoff, processing idempotency |
| WhatsApp | `ADMAN_WHATSAPP_ENABLED=false` — full inbound WhatsApp→AI→WhatsApp path remains a **separate** prerequisite |

### Controlled verification notes (Task 024B)

- Payment claim creates `pending_verification` only; invoice stays unpaid; confirmed payments unchanged.
- After `request_human_handoff`, conversation mode is `human` and `AiService` skips further auto-replies.
- Duplicate `processInboundMessage` on the same inbound id reuses one `ai_message_processings` row.
- Invalid API key → HTTP 401 failure response, empty text, no FakeAiProvider, no payment/claim side effects; real key restored immediately after the test.