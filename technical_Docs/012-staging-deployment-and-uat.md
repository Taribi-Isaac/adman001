# 012 — Staging Deployment & UAT Foundation

## Status of this task (honest)

**Task 014 (2026-09-23):** Local pre-deployment verification was completed. **No remote staging environment was deployed** — required external resources are still unavailable in this workspace.

| Dependency | Status |
|------------|--------|
| Staging VPS / SSH | **BLOCKER — External Resource Required** |
| Staging domain / DNS | **BLOCKER — External Resource Required** |
| TLS certificate | **BLOCKER — External Resource Required** (needs domain + server) |
| Staging MySQL/Redis on remote host | **BLOCKER — External Resource Required** |
| Git repository / remote for release SHA | **BLOCKER — External Resource Required** (working tree has **no `.git`**) |
| GitHub remote / Actions against this tree | **BLOCKED** until Git is initialized and pushed (workflow file exists: `.github/workflows/tests.yml`) |
| Staging email provider | Deferred until server exists |
| Staging WhatsApp Meta app | Deferred until server + HTTPS exist |
| Staging AI API key | Deferred until needed for UAT |

What **is** complete in-repo (Tasks 012–014):

- Staging environment template (`.env.staging.example`) — placeholders only, `APP_DEBUG=false`
- Hardened Super Admin bootstrap (production refused; staging needs `--force-staging`)
- Staging included in production-safety checks / HTTPS force / WhatsApp signature rules
- Deployment plan + smoke-test + UAT checklist (this document), including Task 013 dashboard/PDF/branding
- Local regression verified (tests + frontend build + operator CLI)

When external resources are available, follow the plan below and update the **Deployment record** section with real values. **Do not claim staging is live until a reachable host has been verified.**

---

## Task 014 — Local pre-deployment verification record

Executed on the development workstation against the current application tree (not a remote staging host).

| Check | Result | Notes |
|-------|--------|-------|
| `php artisan test` | PASS (see verification section) | Full suite |
| `npm run build` | PASS | Vite production build |
| `php artisan adman:production-check` | PASS (`APP_ENV=local`) | No local blockers |
| `php artisan adman:production-check --strict` | PASS (local) | |
| `php artisan adman:health` | PASS | database/redis/queue ok |
| `php artisan schedule:list` | PASS | `horizon:snapshot`, `recurring-billing:process-due`, `reminders:process-due` |
| Queue default | PASS | `redis` |
| Horizon tries | PASS | `3` (`config/horizon.php`) |
| Private storage ignore | PASS | `storage/app/private/*` ignored except `.gitignore` |
| Secrets in Git | N/A / SAFE locally | No `.git`; `.env` / `.env.staging` / `.env.production` listed in `.gitignore` |
| Fake AI forbidden in production | PASS | `--strict` fails when `ADMAN_AI_PROVIDER=fake` under `APP_ENV=production` |
| Staging check with local `.env` + `APP_ENV=staging` | Expected FAIL | Reports insecure cookie / missing WA secrets / non-HTTPS URL when WhatsApp still enabled from local env — proves checks fire; real staging must use `.env.staging.example` values |

Local stack observed during verification:

| Component | Value |
|-----------|-------|
| PHP | 8.4.1 |
| Laravel | 13.33.0 |
| Redis | reachable (`PONG`) |
| MySQL CLI | not installed on PATH (app DB still connected via Laravel — SQLite or configured driver) |
| `APP_ENV` | `local` |
| `APP_DEBUG` | enabled (acceptable for local only) |

---

## Deployment plan (before provisioning)

1. Initialize Git in this project (or clone from an approved remote); ensure `.env` is never committed; push; tag intended release (`v0.x-staging` or commit SHA).
2. Provision single VPS (Ubuntu 24.04 LTS recommended).
3. Create staging subdomain DNS A record → server IP.
4. Install Nginx, PHP 8.4 (+ extensions), Composer, Node 20+, MySQL 8, Redis, Certbot.
5. Deploy code; configure `.env` from `.env.staging.example`.
6. Migrate + seed roles; create staging Super Admin with `--force-staging`.
7. Configure Horizon (systemd) + cron `schedule:run`.
8. Enable email sandbox; keep WhatsApp/AI disabled until credentials exist (`ADMAN_WHATSAPP_ENABLED=false`, `ADMAN_AI_ENABLED=false`).
9. Run `php artisan adman:production-check --strict` and smoke tests.
10. Execute UAT checklist with synthetic data only.

Architecture reference: [011-production-readiness.md](011-production-readiness.md).

```text
Internet → Staging Domain → Nginx+TLS → Laravel
                                      ├── MySQL (adman_staging)
                                      ├── Redis
                                      ├── Horizon
                                      └── Scheduler
```

---

## Local Super Admin bootstrap

```bash
# Local development only
php artisan adman:create-super-admin
# optional non-interactive (local):
# ADMAN_SUPER_ADMIN_NAME=... ADMAN_SUPER_ADMIN_EMAIL=... ADMAN_SUPER_ADMIN_PASSWORD=... php artisan adman:create-super-admin
```

```bash
# Staging (on the staging server only)
php artisan adman:create-super-admin --force-staging
```

```text
Production:
Do not use this command.
```

Guarantees:

- No hard-coded password
- Refused when `APP_ENV=production`
- Staging requires explicit `--force-staging`
- Password is never printed
- Roles seeded via `RolesAndPermissionsSeeder` before assignment

---

## Staging server checklist (fill when provisioned)

| Item | Value |
|------|-------|
| Provider | _TBD_ |
| OS | _TBD (recommend Ubuntu 24.04 LTS)_ |
| Specs | _TBD (e.g. 2 vCPU / 4 GB RAM / 40 GB)_ |
| Public IP | _TBD_ |
| SSH user | _TBD (key-based)_ |
| Firewall | 22/80/443 only as needed |
| PHP | 8.4+ |
| Extensions | mbstring, openssl, pdo_mysql, redis, tokenizer, xml, ctype, json, bcmath, fileinfo, gd/imagick as needed |
| Composer | 2.x |
| Node | 20+ |
| MySQL | 8.x database `adman_staging` |
| Redis | local or managed |
| Nginx | document root `public/` |

---

## Domain / DNS / TLS (fill when ready)

| Item | Value |
|------|-------|
| Hostname | e.g. `staging.yourdomain.com` |
| DNS | A/AAAA → server IP |
| Certificate | Certbot / Let’s Encrypt |
| `APP_URL` | `https://staging.yourdomain.com` |
| `SESSION_SECURE_COOKIE` | `true` |

Webhook providers require HTTPS.

---

## Application deploy commands

On the staging host, from the release directory:

```bash
git fetch && git checkout <TAG_OR_SHA>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.staging.example .env   # then edit secrets
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan adman:create-super-admin --force-staging
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan adman:production-check --strict
php artisan adman:health
```

Workers:

```bash
# systemd/supervisor unit recommended
php artisan horizon
```

Cron:

```cron
* * * * * cd /var/www/adman && php artisan schedule:run >> /dev/null 2>&1
```

---

## External services (staging policy)

| Service | Staging policy |
|---------|----------------|
| Email | Sandbox / Mailtrap / SES sandbox; test recipients only |
| WhatsApp | Dedicated test number/app; `ADMAN_WHATSAPP_ENABLED=false` until ready; **app secret required** when enabled |
| AI | Disabled until key available; if enabled, provider must not be `fake` |

Never send staging automation to real customers.

Document delivery remains **generated PDF + secure customer-facing link** (email/WhatsApp). PDF attachments are a separate product decision (deferred).

---

## Deployment record (update after first real deploy)

| Field | Value |
|-------|-------|
| Branch | _not deployed_ |
| Commit SHA | _unavailable — repository has no `.git` in this workspace_ |
| Deployed at | _n/a_ |
| Migration batch | _n/a_ |
| `APP_ENV` | _n/a_ |
| Hostname | _n/a — no staging host_ |
| Operator | _n/a_ |
| Task 014 local verification date | 2026-09-23 |

---

## Smoke test (execute on staging)

Mark Pass/Fail after staging exists. Local automation is **not** a substitute for these rows.

| # | Area | Check | Result |
|---|------|-------|--------|
| 1 | Auth | Login / logout | _blocked — no staging_ |
| 2 | RBAC | Super Admin access | _blocked — no staging_ |
| 3 | Business | View/update settings + branding logo | _blocked — no staging_ |
| 4 | Contacts | Create → promote Customer | _blocked — no staging_ |
| 5 | Quotes | Create → issue → convert | _blocked — no staging_ |
| 6 | Invoices | Create → issue → PDF → secure link | _blocked — no staging_ |
| 7 | Payments | Record partial; claim; confirm | _blocked — no staging_ |
| 8 | Recurring | Controlled schedule + one generation | _blocked — no staging_ |
| 9 | Email | Queue document email (sandbox) | _blocked — credentials + host_ |
| 10 | WhatsApp | Deferred or test number only | _blocked — credentials + HTTPS_ |
| 11 | Reminders | Controlled run; no duplicate | _blocked — no staging_ |
| 12 | AI | Disabled or controlled conversation | _blocked — credentials optional_ |
| 13 | Conversations | Takeover / return to AI | _blocked — no staging_ |
| 14 | Horizon | Job processes; failed jobs visible | _blocked — no staging_ |
| 15 | Scheduler | `schedule:list` matches expected | Local PASS; staging cron _blocked_ |
| 16 | Audit | Key actions recorded | _blocked — no staging_ |
| 17 | Safety | `adman:production-check --strict` passes | Local PASS; staging _blocked_ |
| 18 | Storage | Private PDF not publicly browsable | _blocked — no staging_ |
| 19 | Dashboard | Attention metrics + links (Task 013) | _blocked — no staging_ |
| 20 | PDFs | Quote/invoice/ack layout + logo/fallback | Local unit/feature PASS; visual staging UAT _blocked_ |

---

## UAT checklist (business scenarios)

Use synthetic contacts only. Record Actual / Pass-Fail / Notes during UAT on the **staging host**.

### 1. New prospect communication
- **Objective:** Unknown/prospect can be captured without exposing customer data  
- **Setup:** Staging WhatsApp or internal conversation  
- **Steps:** Inbound message from unlinked identity  
- **Expected:** Message stored; no customer invoice data exposed  

### 2. Prospect becomes customer
- **Objective:** Lifecycle promotion works  
- **Steps:** Promote Prospect → Customer  
- **Expected:** Status Customer; audit entry  

### 3–6. Quote lifecycle
- Create draft → issue → accept → convert to invoice  
- **Expected:** Numbers issued; invoice created from quote; drafts not emailed as final  

### 7–9. Invoice issue & delivery
- Issue invoice → generate PDF → secure link → email (sandbox)  
- **Expected:** Outstanding balance correct; link requires token; email queued/accepted by transport  

### 10–13. Payments
- Partial payment; payment claim; staff confirm; acknowledgement  
- **Expected:** Balance updates only on confirmed payment; claim stays pending until confirm  

### 14. Multiple outstanding invoices
- Customer with ≥2 outstanding invoices claims payment without invoice number  
- **Expected:** Clarification required (AI or staff); no automatic allocation  

### 15. Recurring billing
- Create test schedule; generate once  
- **Expected:** Ordinary invoice created; no auto WhatsApp/email blast  

### 16–17. Reminders
- Controlled -7 / +1 style rule against test invoice  
- **Expected:** One occurrence per rule/channel; skipped if paid  

### 18. Human takeover
- Conversation in AI → Take over  
- **Expected:** No further AI auto-replies  

### 19–21. AI assistance
- Linked customer asks about invoice; reports payment; asks for human  
- **Expected:** Authorized data only; claim unconfirmed; handoff to Human  

### 22–23. Channel delivery
- WhatsApp/email status visible in UI  
- **Expected:** Queued ≠ mailbox delivered; failures visible  

### 24. Secure document link
- Open link; revoke; expire  
- **Expected:** Access denied after revoke/expiry  

### 25. Permissions
- Staff vs missing permission  
- **Expected:** Server 403; no reliance on UI hide alone  

### 26. Audit trail
- Spot-check create/issue/payment/AI handoff  
- **Expected:** Events present without secrets/passwords  

### 27. Failure/retry
- Force failed outbound (bad credential in staging sandbox)  
- **Expected:** Failed job/message visible; retry does not duplicate financial records  

### 28. Operational dashboard (Task 013)
- Create controlled outstanding/overdue invoices, pending claim, issued quote  
- **Expected:** Dashboard counts match; links open filtered lists; empty states sensible; recent activity respects `audit.view`  

### 29. Document presentation & branding (Task 013)
- Generate quote/invoice/payment acknowledgement with: one item, many items, long description, long names, missing optional contact fields, multi-page items  
- With custom logo uploaded and with logo reset to ADMAN default  
- **Expected:** No float/collision layout defects; Bill To never blank when org/name available; logo/fallback renders; calculations unchanged  

### 30. Known logo snapshot limitation (not a UAT failure if understood)
- Issue document with custom logo, then remove logo file from disk  
- **Expected:** Snapshot still stores prior `logo_path`; regenerate falls back to default image without rewriting historical snapshot  

---

## Operator failure map

| Symptom | Look here |
|---------|-----------|
| Jobs stuck | Horizon UI, Redis, `failed_jobs` |
| Scheduler idle | crontab, `schedule:list`, logs |
| WhatsApp 403 | App secret, verify token, HTTPS URL |
| Email silent | `MAIL_*`, Message status, Horizon |
| AI silent | Business AI flags, `ADMAN_AI_*`, `ai_message_processings` |
| 500s | `storage/logs/laravel.log`, `APP_DEBUG` must stay false |
| PDF layout odd | Re-generate after Task 013 templates; check snapshots + logo file presence |

---

## Known limitations

- Staging not yet live in this workspace  
- No Git SHA available until repository is initialized/pushed  
- WhatsApp/AI/email staging verification blocked on external accounts  
- Re-running `RolesAndPermissionsSeeder` syncs Staff permissions (documented in 011)  
- Historical custom logo file deletion falls back at render time (Task 013)  
- Email/WhatsApp still send secure links, not PDF attachments (product decision open)

---

## Intentionally deferred (not defects)

- WhatsApp PDF/media attachments and inbound media download  
- Payment evidence file storage / OCR / AI vision  
- Automatic payment verification  
- Document accent color / multiple templates / designer  
- Dynamic favicon / auth-page business logo  
- Customer portal, analytics platform, payment gateways, S3/CDN  
- Kubernetes / microservices / full automated production CD  

---

## Recommended next step

1. **DECISION REQUIRED:** Approve staging hostname + VPS provider/size.  
2. Initialize Git remote and tag a release (working tree currently has **no `.git`**).  
3. Provision server → deploy using this plan → complete smoke test + UAT tables above.  
4. Only then schedule production cutover.
