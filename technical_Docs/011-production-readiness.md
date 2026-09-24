# 011 — Production Readiness, Operational Hardening & Deployment Foundation

## Purpose

Operational correctness for staging/production deployment of ADMAN after Tasks 001–010.

This is **not** a new product domain. Domain behaviour is documented in Tasks 001–010.

---

## Production architecture (initial)

```text
Internet → DNS/TLS → Nginx → Laravel
                           ├── MySQL (source of truth)
                           ├── Redis (cache, queue, session, Horizon, locks)
                           ├── Horizon workers
                           └── Scheduler (cron → schedule:run)
```

External:

```text
Laravel → Email provider
       → WhatsApp Cloud API
       → AI provider (OpenAI-compatible)
```

Single-server first. No Kubernetes, microservices, or extra brokers.

---

## Environment separation

| | Local | Staging | Production |
|---|--------|---------|------------|
| `APP_ENV` | `local` | `staging` | `production` |
| `APP_DEBUG` | `true` OK | `false` | **must** `false` |
| `APP_URL` | localhost | staging host HTTPS | production host HTTPS |
| Secrets | local only | **separate** from prod | unique, never in Git |
| `QUEUE_CONNECTION` | redis (or sync for quick UI) | redis | **redis** |
| `SESSION_SECURE_COOKIE` | false | true | **true** |
| `LOG_LEVEL` | debug | info | info/warning |
| Mail | `log` | sandbox/provider | real provider |
| WhatsApp | optional / test number | sandbox or disabled | production Meta app |
| AI | optional / `fake` allowed | real or disabled | **`fake` forbidden** |

Operator commands:

```bash
php artisan adman:production-check
php artisan adman:production-check --strict
php artisan adman:health
```

Public liveness: `GET /up` (Laravel default; no secrets). Detailed DB/Redis checks are CLI-only (`adman:health`) so they are not exposed publicly.

---

## Environment variable catalogue

See `.env.example` for the full list. Classification summary:

| Variable | Local | Staging | Production | Secret |
|----------|-------|---------|------------|--------|
| `APP_KEY` | required | required | required | yes |
| `APP_DEBUG` | true OK | false | false | no |
| `DB_*` | required | required | required | password yes |
| `REDIS_*` | required for Horizon | required | required | password if set |
| `QUEUE_CONNECTION` | redis | redis | redis | no |
| `SESSION_SECURE_COOKIE` | false | true | true | no |
| `MAIL_*` | log OK | provider | provider | yes |
| `ADMAN_EMAIL_ENABLED` | optional | optional | optional | no |
| `WHATSAPP_*` | optional | if testing WA | required if WA enabled | tokens/secrets yes |
| `ADMAN_AI_*` | optional | optional | no `fake`; key if AI on | key yes |
| `AWS_*` | optional | optional | optional (MVP uses private disk) | yes |

Never commit `.env`, `.env.production`, or real credentials.

---

## Safety fixes implemented in Task 011

1. **Fake AI blocked in production** — `ADMAN_AI_PROVIDER=fake` does not bind `FakeAiProvider` in production; OpenAI-compatible provider is used and fails safely without keys (no fabricated replies).
2. **AI disabled by default** — `ADMAN_AI_ENABLED` defaults to `false`.
3. **Queue default** — `QUEUE_CONNECTION` config default is `redis` (matches Horizon).
4. **Private disk** — `filesystems.disks.local.serve = false` (PDFs under `storage/app/private`).
5. **Horizon** — worker `tries` aligned to 3; timeout 90s for AI/provider latency.
6. **Scheduler** — `onOneServer()` on billing/reminder schedules.
7. **Trust proxies + HTTPS** — trust proxies for Nginx TLS; `URL::forceScheme('https')` in production.
8. **Production check / health CLI** — `adman:production-check`, `adman:health`.
9. **Boot warnings** — production misconfig logged via `ProductionSafety::logProductionWarnings()`.

---

## Queues & Horizon

Critical jobs (all Redis/Horizon):

| Job | Purpose | Unique | Tries / backoff |
|-----|---------|--------|-----------------|
| `SendOutboundEmailJob` | Email delivery | yes | 3 / 30-120-300 |
| `SendOutboundWhatsAppJob` | WhatsApp template + session text | yes | 3 / 30-120-300 |
| `ProcessInboundAiMessage` | AI reply | yes | 3 / 15-60-180 |
| `ProcessDueInvoiceReminders` | Reminder batch | — | queue |
| `ProcessInvoiceReminderOccurrence` | Reminder send | yes | 3 |
| Recurring billing process | Invoice generation | overlap-safe | command |

Production workers:

```bash
php artisan horizon
# or supervised: php artisan horizon (systemd/supervisor)
```

Horizon UI: `/horizon` — requires `system.horizon` permission.

Failed jobs: Horizon dashboard + `failed_jobs` table.

---

## Scheduler

Cron (required on the app server):

```cron
* * * * * cd /path/to/adman && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled:

- `horizon:snapshot` every 5 minutes
- `recurring-billing:process-due` daily 01:00 (business TZ via app config), `withoutOverlapping` + `onOneServer`
- `reminders:process-due` daily 07:00, same protections

Timezone: business timezone from Task 001 applied at bootstrap when available.

---

## Storage & documents

- Generated PDFs: `storage/app/private/...` via `local` disk
- Not web-browsable; access via staff UI or hashed secure links (`/d/{token}`)
- Token hash + expiry + revoke remain authoritative (Task 004)
- `php artisan storage:link` only links **public** disk — do not place invoices there

MVP: local private disk is acceptable if the whole server (or `storage/app/private`) is backed up. Object storage (S3) is **deferred** until multi-server or DR needs it.

---

## Backup & recovery

### MySQL

- Daily automated dump (or provider snapshot) minimum
- Retain ≥ 7–14 days (adjust to risk)
- Encrypt off-server copies
- Test restore quarterly

```bash
mysqldump -u… -p… adman | gzip > adman-$(date +%F).sql.gz
```

### Files

- Backup `storage/app/private` with the same cadence as DB (PDFs are regenerable for many docs but secure-link hashes and any non-regenerable files need the files)
- Backup `.env` securely offline (not in Git)

### Redis

- Ephemeral for cache/queue/session — not the financial source of truth
- After Redis loss: restart Horizon; sessions may reset; failed jobs may need re-queue from DB/app state

### Restore outline

1. Provision server + MySQL + Redis  
2. Deploy code release  
3. Restore `.env`  
4. Restore DB dump  
5. Restore `storage/app/private`  
6. `composer install --no-dev`, `npm ci && npm run build`  
7. `php artisan migrate --force` (only if dump is behind)  
8. `php artisan config:cache route:cache view:cache`  
9. Start Horizon + cron  
10. `php artisan adman:production-check --strict`  
11. Smoke-test login, invoice PDF, webhook

---

## External services checklist (must exist before go-live)

### Domain / TLS

- [ ] Domain registered  
- [ ] DNS A/AAAA (or CNAME) to server  
- [ ] TLS certificate (Let’s Encrypt or equivalent)  

### Server

- [ ] VPS/OS hardened  
- [ ] Firewall (80/443/SSH only as needed)  
- [ ] PHP 8.3+ with extensions: `mbstring`, `openssl`, `pdo_mysql`, `redis`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `gd` or `imagick` as needed for PDF  
- [ ] Composer 2  
- [ ] Node 20+ for asset build  
- [ ] Nginx  
- [ ] MySQL 8+  
- [ ] Redis  

### Email

- [ ] Provider account (SES/Postmark/SMTP)  
- [ ] From domain SPF/DKIM/DMARC as required by provider  
- [ ] `MAIL_*` + Business outbound email enabled  

### WhatsApp

- [ ] Meta Business + WhatsApp Cloud API  
- [ ] Phone number ID + access token  
- [ ] App secret + webhook verify token  
- [ ] Webhook URL `https://<host>/webhooks/whatsapp` subscribed to `messages`  
- [ ] Approved templates (quote, invoice, reminder, payment ack)  
- [ ] Production never accepts unsigned webhooks (secret required)  

### AI

- [ ] Provider account + production API key  
- [ ] `ADMAN_AI_PROVIDER=openai` (not `fake`)  
- [ ] Enable Business AI flags only when ready  
- [ ] Usage/billing limits reviewed  

### Source control

- [ ] GitHub repo  
- [ ] Protected main branch  
- [ ] Deploy access (SSH deploy key or CI)  

**This task does not create those accounts.**

---

## Staging vs production

```text
Local → Git → Staging → UAT → Production
```

Staging must:

- Use separate DB/Redis/secrets  
- Prefer WhatsApp test number or `ADMAN_WHATSAPP_ENABLED=false`  
- Prefer `MAIL_MAILER=log` or a sandbox inbox  
- Keep `APP_DEBUG=false`  
- Not share production Meta tokens  

---

## Deployment procedure

1. **Prerequisites** — server items above  
2. **Clone release** — `git clone` / `git fetch && git checkout <tag>`  
3. **`.env`** — copy from secure store; set production values; `php artisan key:generate` only if new  
4. **Dependencies** — `composer install --no-dev --optimize-autoloader`  
5. **Assets** — `npm ci && npm run build` (or build in CI and deploy `public/build`)  
6. **Permissions** — `storage/` and `bootstrap/cache/` writable by PHP-FPM user  
7. **Migrate** — `php artisan migrate --force`  
8. **Seed permissions once** — `php artisan db:seed --class=RolesAndPermissionsSeeder --force` (note: re-running syncs Staff permissions)  
9. **Caches** — `php artisan config:cache && php artisan route:cache && php artisan view:cache`  
10. **Horizon** — restart `php artisan horizon` (or `horizon:terminate` then supervisor starts new)  
11. **Cron** — ensure `schedule:run` minute entry  
12. **Nginx** — root `public/`; PHP-FPM; TLS  
13. **Webhooks** — point Meta to production URL  
14. **Verify** — `adman:production-check --strict`, login, create draft invoice, Horizon UI, webhook challenge  

### Rollback

1. Put app in maintenance: `php artisan down`  
2. Check out previous Git tag/release  
3. Restore previous `.env` only if it changed  
4. If DB migrated forward incompatibly: restore DB backup taken **before** migrate  
5. Rebuild caches; restart Horizon  
6. `php artisan up`  
7. Verify  

Always take a DB dump before production migrate.

---

## Operational runbook (short)

| Incident | Action |
|----------|--------|
| Queue stopped | `php artisan adman:health`; restart Horizon/supervisor; check Redis |
| Horizon down | Check process/logs; `php artisan horizon`; permission `system.horizon` for UI |
| Scheduler dead | Check cron; `php artisan schedule:list`; run due command manually if safe |
| WhatsApp webhook 403 | Verify `WHATSAPP_APP_SECRET`, raw-body HMAC, Meta app secret match |
| Email failing | Horizon failed jobs; Message status; mail credentials; Business outbound switch |
| AI unavailable | Provider errors → processing `failed`, no fabricated reply; staff Take over conversations |
| DB issue | Do not invent data; restore from backup if corrupt; keep app in `down` |
| Disk full | Free space; check `storage/logs` and `storage/app/private` |
| Bad deploy | Rollback procedure above |

---

## Security notes (Task 011 review)

- CSRF on web; WhatsApp webhook exempt + signature required in production  
- RBAC + inactive user middleware  
- Production passwords: min 12 mixed/uncompromised  
- Destructive DB artisan commands prohibited in production  
- Secure document links hashed/expiring/revocable  
- AI tools server-authorized; claims never auto-confirm payments  
- Human mode blocks AI auto-reply  

Deferred dedicated pen-test / WAF / 2FA policy review.

---

## Financial / communication integrity (review result)

- Payments/claims/reminders/AI still use existing services; no new financial source of truth  
- Reminder/AI uniqueness constraints remain  
- AI does not reply in Human/Closed modes  
- WhatsApp inbound idempotent on external message ID  

---

## Known limitations / deferred

- No public deep health (DB/Redis) endpoint — intentional  
- S3/object storage deferred  
- Complex CI/CD deferred  
- Staff WhatsApp free-form composer deferred  
- Multi-server active-active needs shared storage + Redis + careful Horizon  

---

## Verification commands

```bash
php artisan adman:production-check --strict
php artisan adman:health
php artisan test
npm run build
php artisan schedule:list
php artisan horizon:status   # when Horizon running
```
