# 016 — Production Deployment

Operational runbook for ADMAN production on DigitalOcean.

Related: Task 016 architecture review; Task 011 production readiness; Task 012 staging (future).

---

## Status (Task 028) — WhatsApp production verified (real device path)

| Item | State |
|------|--------|
| Droplet | `165.232.103.182` (`lon1`), hostname `adman-prod` |
| App | `/var/www/adman` @ `main` |
| Production URL | **`https://adman.raslordeckltd.com`** |
| Config cache | `config.php` `adman:www-data` **640**; `.env` **600** |
| Email | **Resend active** |
| AI | **OpenAI active** |
| WhatsApp | **PRODUCTION VERIFIED** — real Meta inbound (`wamid.HBg…`) → AI → outbound with provider id + status `delivered` |
| Business WhatsApp number | `+234 704 723 0179` (display; Raslordeck) |
| WhatsApp transactional templates | **Live (Task 037R2, 2026-10-01)** — `quote_document`, `invoice_sent`, `invoice_reminder`, `payment_acknowledgement` approved, enabled and tested; 24-hour window handling verified |

### Task 017 security foundation (unchanged)

SSH key-only, no root SSH, UFW 22/80/443, Fail2ban, swap, timezone/NTP — preserved.

### SSH access (operators)

```bash
ssh -i ~/.ssh/id_ed25519 adman@165.232.103.182
```

If the key is passphrase-protected, unlock once per session:

```bash
ssh-add --apple-use-keychain ~/.ssh/id_ed25519
```

Root SSH is rejected by design. Recovery: DigitalOcean web console if needed.

Unban Fail2ban IP (if locked out of SSH temporarily):

```bash
sudo fail2ban-client set sshd unbanip A.B.C.D
```

Optional bootstrap script (already applied manually; retained for reference):

`technical_Docs/scripts/017-phase-b-server-foundation.sh`

### Resource baselines (Task 018)

**Before runtime install** (post Task 017 idle):

```text
Mem: 961Mi total, ~636Mi available, swap 0B used
Disk: ~3.4G used / 24G
Public listen: 22/tcp (80/443 UFW allowed, no app listeners)
```

**After Nginx + PHP-FPM + MySQL + Redis + Composer + Node** (2026-09-24 verified):

```text
Mem: 961Mi total, ~428Mi used, ~532Mi available
Swap: 1.0Gi, ~52Mi used (light; no heavy pressure)
Disk: 4.2G used / 24G (18%)
Load: ~0.37 / 0.29 / 0.15
Top RSS: mysqld ~145Mi, php-fpm master ~34Mi, redis ~12Mi
```

Server remains operational for the next Laravel deploy step. Revisit Droplet size only if Horizon/app workers push sustained swap growth.

---

## Target architecture

```text
Internet
   ↓
adman.raslordeckltd.com   (DNS later — not this phase)
   ↓
DigitalOcean Droplet (Ubuntu 24.04 LTS, 1 vCPU / 1 GB / 25 GB)
   ├── UFW: 22, 80, 443
   ├── Fail2ban (SSH)
   ├── ~1 GB swap
   ├── Nginx / PHP-FPM / MySQL / Redis   [Task 018 — done]
   ├── Laravel ADMAN at /var/www/adman  [Task 019 — done]
   ├── DNS + HTTPS                      [Task 020 — done]
   └── Horizon / scheduler              [Task 021 — done]
```

External (later): SES, OpenAI, WhatsApp Cloud API.

---

## Phase A — Droplet (completed)

| Setting | Value |
|---------|--------|
| Provider | DigitalOcean |
| IPv4 | `165.232.103.182` |
| Region | `lon1` (London) |
| Image | Ubuntu 24.04.4 LTS |
| Plan | `s-1vcpu-1gb` (1 vCPU / 1 GB / 25 GB) |
| Auth | SSH key `~/.ssh/id_ed25519` |
| Default hostname | `ubuntu-s-1vcpu-1gb-lon1` |

Operator verified root SSH before Phase B.

---

## Phase A (historical) — Create checklist

Previously used create settings (kept for reference):

| Setting | Value |
|---------|--------|
| Image | **Ubuntu 24.04 LTS x64** |
| Plan | **Basic** → Regular → **s-1vcpu-1gb** (1 vCPU, 1 GB RAM, 25 GB SSD, 1 TB transfer) |
| Region | Prefer **London (LON1)** for Nigerian users (no DO Lagos region). Alternative: **Frankfurt (FRA1)** |
| VPC | Default VPC for the region is fine |
| Authentication | **SSH key** only — add your public key (do not enable password login) |
| Hostname | e.g. `adman-prod` |
| Tags (optional) | `adman`, `production` |
| Backups | Optional at create time — **see Task 030: no automated backups currently exist** |
| Monitoring | Enable DigitalOcean basic monitoring if offered at create time |
| IPv6 | Optional; if unused, do not publish AAAA later |

### Do not create yet

- Managed databases  
- Managed Redis / Valkey  
- Spaces / CDN  
- Load balancers  
- Additional Droplets / staging  
- App Platform  

### After create — send Cursor (safe to share)

1. Public **IPv4** address  
2. Confirm region (e.g. LON1)  
3. Confirm which **SSH public key** fingerprint was attached  
4. Confirm login user (DigitalOcean images typically allow `root` with your key until hardened)

Do **not** paste private keys, passwords, or API tokens into chat or Git.

### Local SSH key check (your Mac)

Public keys present locally (for reference when selecting a key in DigitalOcean):

- `~/.ssh/id_ed25519.pub`  
- `~/.ssh/id_rsa.pub`  
- `~/.ssh/github_actions_deploy.pub` (deploy-oriented; prefer a dedicated admin key for the Droplet)

Use one admin key for the Droplet. Keep deploy keys separate when GitHub Actions is added later.

Example connect (after create):

```bash
ssh root@YOUR_DROPLET_IPV4
```

---

## Phase B — Security foundation (completed 2026-09-24)

Executed after authenticated `adman` login + sudo were verified, then SSH hardened.

1. Created user `adman` (uid 1000), group `sudo`, authorized key from operator `id_ed25519.pub`
2. Sudoers: `/etc/sudoers.d/90-adman` (`NOPASSWD:ALL`)
3. SSH drop-in: `/etc/ssh/sshd_config.d/99-adman-hardening.conf` — password auth off, root login off, pubkey on
4. Confirmed separate `adman` SSH session + sudo; confirmed root SSH rejected
5. UFW: deny incoming, allow 22/80/443
6. Fail2ban `sshd` jail enabled
7. 1 GB `/swapfile` + fstab + swappiness 10
8. Hostname `adman-prod`, timezone `Africa/Lagos`
9. `apt-get upgrade` (security/updates); rebooted onto kernel `6.8.0-142-generic`
10. Captured baseline (see Status table)

### Next step (Task 022+)

External production integrations: **Resend** (email) when API key is available → OpenAI → WhatsApp. Then UAT and deploy automation.

---

## Task 018 — Runtime stack (completed 2026-09-24)

Repository compatibility checked against `composer.json` / CI (`PHP ^8.3`, Node 22) before install. Application files were not modified.

### Installed versions

| Component | Version / notes |
|-----------|-----------------|
| Nginx | 1.24.0 (Ubuntu) |
| PHP CLI / FPM | 8.3.6 |
| MySQL | 8.0.46-0ubuntu0.24.04.4 |
| Redis | 7.0.15 |
| Composer | 2.10.3 |
| Node / npm | 22.23.3 / 10.9.9 (NodeSource) |

### PHP extensions (verified via `php -m`)

`bcmath`, `ctype`, `curl`, `fileinfo`, `gd`, `intl`, `json`, `mbstring`, `openssl`, `pcntl`, `pdo_mysql`, `posix`, `redis`, `tokenizer`, `xml`, `zip`

Chosen for Laravel 13, DomPDF (`gd`), Horizon (`pcntl`/`posix`/`redis`), MySQL, and Redis — without installing unused PHP versions.

### PHP-FPM (`/etc/php/8.3/fpm/pool.d/www.conf`)

| Setting | Value | Rationale |
|---------|--------|-----------|
| `pm` | `ondemand` | No idle workers at rest on 1 GB RAM |
| `pm.max_children` | `4` | Caps concurrent PHP workers |
| `pm.process_idle_timeout` | `10s` | Recycle idle ondemand workers quickly |
| `pm.max_requests` | `200` | Mitigate leaks without frequent churn |

`start_servers` / spare-server settings left commented (dynamic-mode only). Service enabled + active; config test successful.

### Nginx

- Enabled/active; `nginx -t` successful
- Default site is a safe placeholder (`return 200`) — not a Laravel vhost
- Listens on `0.0.0.0:80` and `[::]:80`
- No domain/SSL site for `adman.raslordeckltd.com` yet

### MySQL

- Config drop-in: `/etc/mysql/mysql.conf.d/99-adman.cnf`
- `bind-address = 127.0.0.1`, `mysqlx-bind-address = 127.0.0.1`
- `innodb_buffer_pool_size = 128M`, `innodb_buffer_pool_instances = 1`
- `max_connections = 50`
- Local ping/version verified; no production DB/user created in this task
- Socket: `127.0.0.1:3306` only (not public)

### Redis

- Bind `127.0.0.1` and `::1`
- `maxmemory 64mb`, `maxmemory-policy allkeys-lru`
- `redis-cli ping` → `PONG`
- Socket: `127.0.0.1:6379` + `[::1]:6379` only

### Composer / Node

- Composer 2 installed via official installer
- Node 22 via NodeSource (aligned with `.github/workflows` Node 22)
- No application `composer install` / `npm run build` in this task

### Network verification (`ss -lntp` + UFW)

| Port | Binding | Access |
|------|---------|--------|
| 22 | `0.0.0.0` / `::` | Public (UFW allow) |
| 80 | `0.0.0.0` / `::` | Public (UFW allow) |
| 443 | — (no listener yet) | UFW allow (for later TLS) |
| 3306 | `127.0.0.1` | Local only |
| 6379 | `127.0.0.1` / `::1` | Local only |

### Not done (deferred from Task 018)

Laravel deploy, app DB/user, `.env`, Horizon, Supervisor, scheduler, DNS, Let's Encrypt, SES, OpenAI, WhatsApp, GitHub Actions deploy.

---

## Task 019 — Laravel production deployment (completed 2026-09-24)

### Location and Git

| Item | Value |
|------|--------|
| App root | `/var/www/adman` |
| Nginx document root | `/var/www/adman/public` |
| Remote | `https://github.com/Taribi-Isaac/adman001.git` |
| Branch | `main` |
| Commit | `8eb53dfe2911401abf8f6d6af8e3ad1a928535f4` |
| Working tree | Clean (after deploy) |

### Runtime deviation (PHP 8.4)

Task 018 installed PHP 8.3.6. `composer.lock` resolves Symfony 8.1 components that require **PHP >= 8.4.1**. Production was upgraded to **PHP 8.4.25** (ondrej/php PPA) with matching FPM extensions; PHP 8.3-FPM disabled. `composer.json` still declares `^8.3`; lockfile drives the practical floor.

### Production `.env` (no secrets documented)

- `APP_ENV=production`, `APP_DEBUG=false`
- `APP_URL=http://165.232.103.182` (IP until DNS/TLS)
- MySQL: `127.0.0.1`, database `adman`, user `adman_app` (password only in server `.env`)
- Redis: cache, queue, session
- `MAIL_MAILER=log`; `ADMAN_EMAIL_ENABLED=false`
- `ADMAN_WHATSAPP_ENABLED=false`; `ADMAN_AI_ENABLED=false`
- Real `APP_KEY` generated on server via `php artisan key:generate --force`

### Deploy commands (reference)

```bash
cd /var/www/adman
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
php artisan key:generate --force   # first deploy only
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan db:seed --class=BusinessSeeder --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

### Ownership / permissions

- Code: `adman:www-data`
- Writable by deploy user + PHP-FPM: `storage/`, `bootstrap/cache/` (group `www-data`, group-writable)
- `.env`: mode `600`, not web-accessible
- Default disk root: `storage/app/private` (`serve => false`) — not exposed by Nginx

### Nginx

- Site file: `/etc/nginx/sites-available/adman` (enabled); default placeholder removed
- `fastcgi_pass unix:/run/php/php8.4-fpm.sock`
- Denies `.env`, `.git`, `composer.json`, `artisan`, and `/storage/app/`
- HTTP only on port 80 (IP access)

### Super Administrator

`adman:create-super-admin` **refuses** `APP_ENV=production` by design. First admin created via controlled one-shot break-glass: `APP_ENV=local` override for that single Artisan invocation only, then production config re-cached. Email: `admin@raslordeckltd.com`. Password stored once on the server at `~/.adman-super-admin-once` (mode 600) for operator retrieval into a password manager — **delete after use**.

### Validation (verified)

| Check | Result |
|-------|--------|
| `GET /up` | 200 |
| `GET /login` | 200 (Vite assets present) |
| `GET /` | 302 (auth redirect) |
| MySQL / Redis | connected (`adman:health` OK) |
| Storage read/write | OK on `local` (private) disk |
| `/.env`, `/.git/HEAD`, `/composer.json`, `/artisan` | 404 |
| `adman:production-check` | exit 0 (risks only: insecure cookie + non-HTTPS URL — expected pre-TLS) |
| `adman:production-check --strict` | exit 1 (**EXPECTED DEFERMENT** until Task 020 HTTPS) |
| `schedule:list` | definitions present; cron/systemd **not** installed yet |
| UFW / localhost MySQL+Redis | unchanged / verified |

### Post-deploy resources (2026-09-24)

```text
Mem: ~457Mi used / ~504Mi available of 961Mi
Swap: ~156Mi used of 1.0Gi (elevated vs Task 018 ~52Mi; monitor; no sustained thrash observed)
Disk: 4.9G used / 24G (21%)
Load: ~0.37 / 0.61 / 0.35
Top RSS: mysqld ~102Mi, php-fpm worker ~50Mi, php-fpm master ~19Mi, redis ~10Mi
```

### Remaining after Task 019 / 020 / 021

- External credentials: **Resend API key** (email), OpenAI, WhatsApp (when ready)
- Manual production UAT
- GitHub Actions deploy automation
- Commit/push local `config/horizon.php` + `016-production-deployment.md` to GitHub when ready

---

## Task 021 — Horizon, queues & scheduler (completed 2026-09-24)

### Horizon configuration (`config/horizon.php`)

Production previously defaulted to `maxProcesses => 10` (unsafe on 1 GB). Updated to:

| Setting | Value |
|---------|--------|
| Supervisor | `supervisor-1` |
| Queue | `default` (redis) |
| `maxProcesses` | **1** |
| `balance` | `simple` |
| Worker `memory` | 128 MB |
| `maxJobs` | 100 (recycle) |
| `tries` | 3 |
| `timeout` | 90 s |
| Master `memory_limit` | 64 MB |

Do not raise `maxProcesses` without measuring RAM/swap under real load.

### systemd services

| Unit | Role |
|------|------|
| `adman-horizon.service` | Runs `php artisan horizon` as `adman:www-data`; `MemoryMax=250M`; restart on failure |
| `adman-scheduler.timer` | Every minute |
| `adman-scheduler.service` | oneshot `php artisan schedule:run` as `adman` |

Commands:

```bash
sudo systemctl status adman-horizon
sudo systemctl restart adman-horizon
sudo systemctl status adman-scheduler.timer
journalctl -u adman-horizon -f
journalctl -u adman-scheduler -n 50
php artisan horizon:status
php artisan horizon:terminate   # graceful; systemd restarts
```

Logs: systemd journal (`SyslogIdentifier=adman-horizon` / `adman-scheduler`).

### Laravel schedules (unchanged; OS only invokes `schedule:run`)

- `horizon:snapshot` every 5 minutes
- `recurring-billing:process-due` daily 01:00 (`withoutOverlapping`, `onOneServer`)
- `reminders:process-due` daily 07:00 (`withoutOverlapping`, `onOneServer`)

### Horizon UI

`/horizon` — `viewHorizon` gate / `system.horizon` permission. Unauthenticated → **403**. Not public.

### Queue verification

- Dispatched `App\Jobs\ProcessDueRecurringBillingSchedules` → Horizon **DONE** (~27 ms)
- No customer email/WhatsApp/AI messages sent
- External providers still deferred; jobs that need them will fail until credentials exist (**EXPECTED**)

### Resource measurements

| Phase | Available RAM | Swap used | Notes |
|-------|---------------|-----------|-------|
| Pre-Horizon | ~480 Mi | ~153 Mi | Baseline |
| Horizon running (~90s) | ~348 Mi | ~152 Mi | ~195 Mi RSS Horizon tree; swap flat |
| Post-reboot | ~364 Mi | **0 B** | Services auto-started; swap cleared |

No sustained heavy swapping observed. PHP-FPM left at `ondemand` / `max_children=4`.

### Restart / reboot

- `systemctl restart adman-horizon` → workers return
- Controlled reboot → Horizon + scheduler timer enabled/active; `horizon:status` running; HTTPS `/up` 200

---

## Task 022 — Resend package (completed)

| Item | State |
|------|--------|
| Provider | **Resend** (not Amazon SES) |
| Package | `resend/resend-php` v1.15.0 on `main` @ `76b0c1b` and production |
| Laravel mailer | Built-in `resend` transport + `config/services.php` `RESEND_API_KEY` |
| Architecture | Unchanged: `EmailOutboundService` → `SendOutboundEmailJob` → Horizon → `LaravelMailEmailDeliveryAdapter` → Mail |

Task 022 left production send disabled until the API key was available.

## Task 023 — Resend production activation (completed 2026-09-25)

| Item | State |
|------|--------|
| Domain | `raslordeckltd.com` — verified sender identity accepted by Resend API |
| From (live send) | `"ADMAN Business" <no-reply@raslordeckltd.com>` (`Business.email`; `MAIL_FROM_ADDRESS` fallback aligned to same address) |
| Production `.env` | `MAIL_MAILER=resend`; `ADMAN_EMAIL_ENABLED=true`; `RESEND_API_KEY` present (server-only; never in Git) |
| `.env` permissions | `600` `adman:www-data` |
| Controlled send | **Passed** — issued invoice `INV-00001` → authorized recipient `admin@raslordeckltd.com` |
| Queue / Horizon | `SendOutboundEmailJob` **DONE** (~922 ms); workers = **1**; failed_jobs = 0 |
| ADMAN message | status `sent` (provider accepted); conversation history updated |
| Resend | message accepted; `last_event` progressed to **`delivered`** (provider mailbox event — ADMAN does not yet store webhook delivery status) |
| PDF | `INV-00001.pdf` generated (`application/pdf`, valid `%PDF-` header) and attached via `DocumentOutboundMail` |
| Horizon concurrency | **unchanged** (`maxProcesses=1`) |

### Activation procedure (repeatable)

1. Ensure Resend domain `raslordeckltd.com` is verified; use a `@raslordeckltd.com` From.
2. Set server `/var/www/adman/.env` only (never Git / `.env.example` values / chat):

```env
MAIL_MAILER=resend
RESEND_API_KEY=<production secret>
ADMAN_EMAIL_ENABLED=true
MAIL_FROM_ADDRESS=no-reply@raslordeckltd.com
```

3. Rebuild config with group-readable cache files (PHP-FPM runs as `www-data`):

```bash
cd /var/www/adman
umask 002
php artisan config:cache
# If config.php is mode 600, PHP-FPM cannot boot the app (HTTP 500):
sudo chown adman:www-data bootstrap/cache/config.php
sudo chmod 664 bootstrap/cache/config.php
sudo systemctl restart adman-horizon
```

4. Verify without dumping secrets:

```bash
php artisan tinker --execute='echo config("mail.default")." enabled=".(config("adman.email.enabled")?"true":"false")." key=".(filled(config("services.resend.key"))?"yes":"no");'
```

### Controlled email + PDF test procedure

1. Use an **authorized** recipient only (e.g. `admin@raslordeckltd.com`) — never customers for activation tests.
2. Issue an invoice/quote (or confirmed payment) via existing ADMAN services/UI.
3. Queue via `EmailOutboundService::queueInvoiceEmail` (or Send by Email in UI).
4. Confirm Horizon processes `SendOutboundEmailJob` (`journalctl -u adman-horizon`).
5. Confirm message status `sent`, Resend dashboard/API shows the message, recipient receives PDF.
6. ADMAN `sent` ≠ durable webhook-confirmed delivery unless Resend events are integrated later.

### Memory / swap review (Task 023)

| Sample | Available RAM | Swap used | Load | Notes |
|--------|---------------|-----------|------|-------|
| Pre-send | ~438 Mi | ~154 Mi | ~0.00 | si/so ≈ 0 |
| Post-send | ~409 Mi | ~154 Mi | ~0.00 | Horizon worker RSS ~82 Mi after PDF job; no OOM |
| Idle (+45s) | ~403 Mi | ~154 Mi | ~0.00 | Swap flat — not growing |

Findings:

- Task 022’s “~780 Mi swap” was a **unit misread** (~780 **Ki** historically / current ~154 Mi used of 1.0 Gi).
- Swap is **not actively thrashing** (`si`/`so` ≈ 0 across samples).
- Largest `VmSwap` holder: **MySQL** (~112 Mi) — historical pressure pages; Horizon/PHP-FPM/Redis not the primary swap consumers.
- No Droplet resize, no Horizon worker increase, no MySQL/PHP-FPM memory retune required.

### Config cache permission note

`php artisan config:cache` as `adman` can write `bootstrap/cache/config.php` as mode `600`, or the cache file may be missing after `config:clear`. PHP-FPM (`www-data`) then cannot load config (especially when `.env` remains mode `600` and is intentionally unreadable by `www-data`) → HTTP 500 on `/login` and Meta webhook GET verification.

**Intended production model (Task 026):**

| Path | Owner:group | Mode | Why |
|------|-------------|------|-----|
| `.env` | `adman:www-data` | `600` | Secrets stay owner-only; never rely on PHP-FPM reading `.env` |
| `bootstrap/cache/config.php` | `adman:www-data` | `640` | PHP-FPM reads cached config; group-readable, not world-readable, not group-writable |

Rebuild procedure:

```bash
cd /var/www/adman
umask 027
php artisan config:cache
php artisan route:cache
sudo chown adman:www-data bootstrap/cache/config.php bootstrap/cache/routes-v7.php
sudo chmod 640 bootstrap/cache/config.php bootstrap/cache/routes-v7.php
sudo systemctl reload php8.4-fpm
sudo systemctl restart adman-horizon
```

Do **not** make `.env` world-readable or group-readable to “fix” FPM. Always ship a readable config cache instead.

### WhatsApp webhook GET handshake

- Callback URL: `https://adman.raslordeckltd.com/webhooks/whatsapp`
- Meta sends `GET` with `hub.mode=subscribe`, `hub.verify_token`, `hub.challenge`
- ADMAN returns HTTP 200 + raw challenge (`text/plain`) when the token matches `WHATSAPP_WEBHOOK_VERIFY_TOKEN`
- Invalid token → HTTP 403 (not 500)

GET verification works even while `ADMAN_WHATSAPP_ENABLED=false`. Keep WhatsApp disabled until App Secret, Access Token, and Phone Number ID are configured.

## Task 024 — OpenAI AI activation (completed via Task 024B, 2026-09-25)

| Item | State |
|------|--------|
| Git | `main` synchronized; production on intended commit after docs push |
| Architecture | Task 010 preserved — `AiProvider` → `OpenAiCompatibleProvider` (HTTP Chat Completions) |
| Model | `gpt-4o-mini` (unchanged) |
| Env | `ADMAN_AI_ENABLED=true`; `ADMAN_AI_PROVIDER=openai`; `ADMAN_AI_API_KEY` **present: yes**; base URL / timeout defaults |
| Business flags | `ai_enabled` + `ai_customer_responses_enabled` **true** (Settings → AI fields) |
| Fake provider | Forbidden; production binds real provider only |
| Live OpenAI request | **Passed** (auth accepted; model `gpt-4o-mini`; ~1.3–3.5 s) |
| Controlled tests | Business context ✓ · auth denial ✓ · payment claim (pending_verification) ✓ · no confirmed payment ✓ · human handoff ✓ · idempotent processing row ✓ |
| Failure test | Invalid key → HTTP 401 safe failure; no fabricated text; no financial changes; real key restored |
| Resources | Available RAM ~408→405→397 Mi; swap ~150–151 Mi flat; Horizon RSS ~64–66 Mi; load ~0.0x; no worker increase |
| WhatsApp | Still disabled — full customer WhatsApp journey is a **separate** external prerequisite |
| Email | Resend still healthy (`MAIL_MAILER=resend`) |
| Horizon | Unchanged — 1 worker |

### Activation procedure (repeatable)

1. Set server `/var/www/adman/.env` only (never Git/chat):

```env
ADMAN_AI_ENABLED=true
ADMAN_AI_PROVIDER=openai
ADMAN_AI_API_KEY=<production secret>
ADMAN_AI_MODEL=gpt-4o-mini
```

2. `umask 002 && php artisan config:cache` then `adman:www-data` `664` on `bootstrap/cache/config.php`; `sudo systemctl restart adman-horizon`
3. Enable Business AI flags in Settings → AI
4. Smoke-test provider via `OpenAiCompatibleProvider::complete` before customer traffic
5. Full inbound WhatsApp path requires WhatsApp production credentials (separate task)

---

## DNS / SSL (Task 020 — completed 2026-09-24)

### DNS

| Fact | Value |
|------|--------|
| Hostname | `adman.raslordeckltd.com` |
| Nameservers | `nsc.go54.com`, `nsd.go54.com` (Go54) |
| A record | `165.232.103.182` only (TTL 14400) |
| Unrelated records | Unchanged (apex/MX/SPF/nameservers) |

Verified against authoritative NS and public resolvers (`8.8.8.8`, `1.1.1.1`) after duplicate A removal.

Server `/etc/hosts` includes `165.232.103.182 adman.raslordeckltd.com` so local tooling is not poisoned by stale recursive cache.

### TLS / Nginx

- Certbot + `python3-certbot-nginx` installed
- Certificate: `/etc/letsencrypt/live/adman.raslordeckltd.com/` (CN/SAN match hostname; expires 2026-12-23)
- Site: `/etc/nginx/sites-enabled/adman` — root `/var/www/adman/public`, PHP 8.4 FPM socket, deny `.env`/`.git`/project files/`storage/app`
- HTTP `:80` → 301 HTTPS; HTTPS `:443` active
- Renewal: `certbot.timer` enabled/active; `certbot renew --dry-run` succeeded

### Laravel HTTPS settings

```env
APP_URL=https://adman.raslordeckltd.com
SESSION_SECURE_COOKIE=true
APP_ENV=production
APP_DEBUG=false
```

Config/route/view caches rebuilt after `.env` change. `APP_KEY` / DB credentials not rotated.

### Verification

| Check | Result |
|-------|--------|
| `GET https://…/up` | 200 |
| `GET https://…/login` | 200 (Vite assets present) |
| HTTP → HTTPS | 301 |
| Session cookies | `adman-session` Secure+HttpOnly; `XSRF-TOKEN` Secure |
| `/.env`, `/.git/HEAD`, `/composer.json`, `/artisan`, private storage paths | 404 |
| MySQL/Redis | localhost-only; UFW 22/80/443 |
| `adman:production-check` / `--strict` | PASS |
| `adman:health` | PASS |
| `schedule:list` | definitions present; OS timer added in Task 021 |

### Post-TLS resources (approx.)

```text
Mem: ~489Mi used / ~471Mi available of 961Mi
Swap: ~153Mi used of 1.0Gi
Disk: 4.9G / 24G (21%)
Load: ~0.10 / 0.06 / 0.04
```

---

## Recovery considerations

- DigitalOcean console (web VNC) remains available if SSH is misconfigured — prefer fixing via console rather than destroy  
- Keep at least one working SSH session during sshd changes  
- Snapshot the Droplet in DO UI before risky later steps if desired  

---

## Security boundaries (ongoing)

| Public | Localhost only (when installed) |
|--------|----------------------------------|
| 22 SSH | 3306 MySQL |
| 80 HTTP | 6379 Redis |
| 443 HTTPS | |

---

## Task 025 — WhatsApp Cloud API activation (externally blocked 2026-09-25)

| Item | State |
|------|--------|
| Architecture | Task 008 preserved — `WhatsAppCloudApiAdapter`, webhook, inbound/outbound services, AI handoff via Task 010 |
| Webhook URL | `https://adman.raslordeckltd.com/webhooks/whatsapp` |
| Env vars | `ADMAN_WHATSAPP_ENABLED`, `WHATSAPP_*` (see `.env.example` placeholders) |
| Access token present | **no** |
| Phone number ID configured | **no** |
| Verify token configured | **no** |
| App secret configured | **no** |
| `ADMAN_WHATSAPP_ENABLED` | **false** (correct until secrets exist) |
| GET verify (no/wrong token) | **403** |
| POST without signature | **403** `Invalid signature` |
| Business `outbound_whatsapp_enabled` | true |
| OpenAI / Resend | Unchanged / healthy |
| E2E WhatsApp↔AI | **Not run** — blocked on Meta credentials |

### Operator action to finish Task 025

1. Complete Meta Business + WhatsApp Cloud API setup (permanent token, phone number ID, app secret).
2. Put secrets only in `/var/www/adman/.env` (never chat/Git).
3. Configure Meta webhook Callback URL + Verify Token; subscribe to `messages`.
4. Set `ADMAN_WHATSAPP_ENABLED=true`, rebuild config cache (group-readable), restart Horizon.
5. Controlled outbound + inbound + AI safety E2E (payment claim / handoff) before customer traffic.
6. Approve matching message templates for business-initiated sends outside the 24h window.

## Task 026 — Config-cache restore & Meta webhook GET ready (2026-09-26)

| Item | State |
|------|--------|
| Root cause | Missing `bootstrap/cache/config.php` + `.env` mode `600` → PHP-FPM could not load APP_KEY/config → HTTPS 500 |
| Fix | Rebuilt config/route cache as `adman:www-data` mode **`640`**; left `.env` at **`600`** |
| `/up` / `/login` | **200** |
| Invalid webhook GET | **403** |
| Valid Meta-style webhook GET | **200** + exact challenge (`text/plain`) |
| `ADMAN_WHATSAPP_ENABLED` | **false** (credentials incomplete) |
| Meta Dashboard “Verify and save” | Operator action — ADMAN handshake verified server-side; retry Meta UI now |

### Remaining before WhatsApp E2E

- `WHATSAPP_APP_SECRET`
- `WHATSAPP_ACCESS_TOKEN`
- `WHATSAPP_PHONE_NUMBER_ID`
- Meta webhook subscription (`messages`) after Verify and save
- Approved templates for business-initiated sends outside the 24h window

## Task 027 — WhatsApp activation (2026-09-26)

| Item | State |
|------|--------|
| Credentials in `.env` | Verify token, App Secret, Access Token, Phone Number ID, WABA ID — **all present** |
| Config cache | Rebuilt so PHP-FPM sees secrets (`640` / `600` model) |
| Meta callback | Graph `webhook_configuration.application` = `adman.raslordeckltd.com/webhooks/whatsapp` (**Verify and save** evidence) |
| WABA `subscribed_apps` | 1 app |
| Graph phone lookup | HTTP 200 (token + phone number ID accepted) |
| `ADMAN_WHATSAPP_ENABLED` | **true** |
| Signature tests | Valid → 200 `EVENT_RECEIVED`; invalid/missing → 403 |
| Signed inbound probe | Message persisted → `ProcessInboundAiMessage` DONE (~2s) → OpenAI → `SendOutboundWhatsAppJob` DONE |
| Outbound to probe number | Message status `failed` (`WhatsApp: Message undeliverable`) — probe used a non-real recipient |
| Real Meta inbound (`wamid.HBg…`) during monitor | **none** |
| AI safety (tools) | Business Q ✓ · handoff → human + skip ✓ · payment claim `pending_verification`, invoice unpaid ✓ |
| Resources | Available RAM ~380–441 Mi; swap ~151 Mi flat; Horizon 1 worker; failed_jobs=0 |

### Controlled live-phone E2E (operator)

1. From an authorized personal WhatsApp, message business number **+234 704 723 0179**: `Hello ADMAN`
2. Confirm ADMAN conversation shows inbound + AI outbound **sent/delivered**
3. Then run handoff / payment-claim scenarios on that conversation

Until step 2 succeeds, do not treat customer WhatsApp delivery as fully proven.

## Task 028 — Real WhatsApp device verification (completed 2026-09-26)

| Item | State |
|------|--------|
| Real inbound | Multiple Meta webhooks with `wamid.HBg…` persisted |
| AI processing | `AiMessageProcessing` **completed** (1 row per inbound; idempotent) |
| Real outbound | AI replies with Meta provider ids (`wamid.HBg…`); status progressed to **`delivered`** |
| Example pair | inbound `38` → processing → outbound `39` (`delivered`, `sent_at` set) |
| Active conversation | mode **AI** after closure checks |
| Signature security | valid 200 / invalid+missing 403 |
| Payment claim regression | `pending_verification`; invoice unpaid; confirmed payments unchanged |
| Human handoff regression | human mode + AI skip; restored to AI |
| Resources | Available RAM ~374 Mi; swap ~147 Mi; load ~0.07; Horizon 1 worker; failed_jobs=0 |
| Classification | **PRODUCTION VERIFIED** |

### Controlled production test procedure (operators)

1. From an authorized personal WhatsApp, message `+234 704 723 0179` (e.g. `Hello ADMAN`).
2. Confirm ADMAN records inbound (`wamid.HBg…`) and AI outbound (`delivered` with provider id) within seconds.
3. Optional: ask a general business question; trigger human handoff; create a payment claim (must stay `pending_verification`).

### Remaining WhatsApp limitations (not defects)

- Approved Meta templates still required for business-initiated sends outside the 24-hour customer-care window
- No OCR / media AI / broadcasts
- Unknown WhatsApp senders do not auto-become Customers
- Delivery/read status persistence is best-effort via existing status webhooks (outbound `delivered` observed in production)

## Next step

WhatsApp production path is verified. Keep Horizon at 1 worker. Next priority: CI/CD (Task 029).

---

## Task 029 — Production CI/CD (GitHub Actions → Droplet)

### Pipeline model

```text
feature / develop work
        ↓
PR / merge into main
        ↓
GitHub Actions workflow: tests
        ↓ (on success, push to main only)
GitHub Actions workflow: deploy-production
        ↓ SSH (deploy key)
DigitalOcean Droplet (adman@…)
        ↓
scripts/deploy-production.sh
        ↓
git fetch → checkout approved main SHA
composer / npm / Laravel optimize / Horizon restart
        ↓
health verification
```

Production tracks **`origin/main` only**. `develop` is never auto-deployed.

### CI — `.github/workflows/tests.yml`

| Trigger | `push` to `main`, all `pull_request`s |
| Steps | Checkout → PHP **8.4** + Node 22 → `composer install` → `.env` + key → `npm ci` → `npm run build` → Pint (`--test`) → `php artisan test` (Pest) |
| Notes | No MySQL service in CI — Pest uses `sqlite :memory:` from `phpunit.xml`. Production still uses MySQL on the Droplet. Full `composer ci:check` / `composer test` (PHPStan + Vue oxfmt/types) is deferred until a dedicated hygiene pass; those checks currently fail on the existing tree. |
| Failure | Workflow fails; deploy does **not** run |

`composer ci:check` runs frontend check, PHPStan, Pint, and Pest.

### Deploy — `.github/workflows/deploy-production.yml`

| Trigger | (1) `workflow_run` after **tests** succeeds for a **push** to `main`; (2) `workflow_dispatch` with input `confirm=deploy` |
| Concurrency | `group: production-deploy`, `cancel-in-progress: false` (second deploy waits; does not cancel the first) |
| Mechanism | SSH + pipe `scripts/deploy-production.sh`; server uses **git** (no rsync of app tree; `.env` never leaves the server) |

### Required GitHub Actions secrets (names only)

| Secret | Purpose |
|--------|---------|
| `DEPLOY_HOST` | Droplet IP / hostname |
| `DEPLOY_USER` | SSH user (`adman`) |
| `DEPLOY_SSH_KEY` | Private key for the Actions deploy key |
| `DEPLOY_SSH_KNOWN_HOSTS` | `ssh-keyscan` output for the host |

Application secrets (OpenAI, Resend, WhatsApp, `APP_KEY`, DB) stay in the server `.env` only. Do **not** put them in GitHub Actions.

### Server-side requirements

- App root: `/var/www/adman`
- Deploy user `adman` with passwordless sudo for `systemctl reload php8.4-fpm`, `systemctl restart adman-horizon`, and related unit checks
- Deploy public key in `~adman/.ssh/authorized_keys` (comment `github-actions-deploy`)
- `.env` present, mode `600`, **gitignored**
- Node, Composer, PHP 8.4 CLI available to `adman`
- Horizon unit `adman-horizon` and timer `adman-scheduler.timer`
- systemd drop-in `UMask=0002` for `php8.4-fpm`, `adman-horizon`, `adman-scheduler` (`/etc/systemd/system/<unit>.service.d/umask.conf`) — required with the private disk `0770` directory permissions (Task 030)

### Deploy script commands (`scripts/deploy-production.sh`)

Environment: `DEPLOY_SHA=<full commit sha>` (required). Optional: `SKIP_MIGRATE=1`, `APP_DIR`, `HEALTH_URL`.

Sequence:

1. `flock` lock `/tmp/adman-production-deploy.lock`
2. Assert `.env` exists and is not git-tracked
3. `git fetch` → `checkout -B main <SHA>` → `reset --hard <SHA>` (**no** `git clean`; untracked `.env` / storage / backups preserved)
4. `composer install --no-dev --optimize-autoloader`
5. `npm ci` && `npm run build`
6. `php artisan migrate --force` (never `migrate:fresh`)
7. `umask 027` → `config:cache` / `route:cache` / `view:cache` → config/routes cache `640` `adman:www-data`; `.env` restored to `600`
8. Reload PHP-FPM; `horizon:terminate` + restart `adman-horizon`; confirm scheduler timer active
9. Verify `/up`, `/login`, `adman:production-check`, `--strict`, Horizon status, config **presence** (Resend / OpenAI / WhatsApp) without printing secrets

### Health verification (after deploy)

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://adman.raslordeckltd.com/up
curl -sS -o /dev/null -w '%{http_code}\n' https://adman.raslordeckltd.com/login
cd /var/www/adman
php artisan adman:production-check
php artisan adman:production-check --strict
php artisan horizon:status
systemctl is-active adman-horizon adman-scheduler.timer
git rev-parse HEAD   # must match intended origin/main SHA
```

### Rollback (simple)

1. Identify bad deploy: `cd /var/www/adman && git rev-parse HEAD` and GitHub Actions run for `deploy-production`
2. Choose previous known-good SHA from `git log --oneline -20` or GitHub `main` history
3. Re-run deploy for that SHA (preferred: Actions `workflow_dispatch` after checking out that commit on `main` is **not** required — use emergency manual):

```bash
ssh adman@<host>
cd /var/www/adman
DEPLOY_SHA=<known-good-sha> ./scripts/deploy-production.sh
# or, if script missing on that old tree:
DEPLOY_SHA=<known-good-sha> bash -s < /path/to/deploy-production.sh
```

4. Script rebuilds caches, restarts Horizon, re-runs health checks
5. Confirm `/up`, `/login`, production-check, integrations config presence

Do **not** restore DB unless a migration was the failure mode; take a DB dump before risky migrates. Do not `migrate:fresh`.

### Emergency manual deployment

```bash
ssh adman@<host>
cd /var/www/adman
git fetch origin
DEPLOY_SHA=$(git rev-parse origin/main)
DEPLOY_SHA="$DEPLOY_SHA" ./scripts/deploy-production.sh
```

If Actions is unavailable, the same script is the supported path. Preserve `.env`; never copy secrets into the repo.

### Troubleshooting

| Symptom | Check |
|---------|--------|
| Deploy skipped | Was the event a **push** to `main`? Did `tests` succeed? `workflow_run` ignores failed CI and non-push events (e.g. PR-only) |
| SSH failure | Secrets `DEPLOY_*` present? Deploy pubkey still in `authorized_keys`? Host key in `DEPLOY_SSH_KNOWN_HOSTS`? |
| Lock error | Another deploy holds `/tmp/adman-production-deploy.lock` — wait or inspect `ps` |
| HTTP 500 after deploy | Config cache mode — must be `640` readable by `www-data`; `.env` stays `600` (Task 026) |
| Horizon down | `systemctl status adman-horizon`; `journalctl -u adman-horizon -n 50` |
| Wrong commit | `git rev-parse HEAD` vs Actions “Deploy target” SHA |

### Task 029 verification record (2026-09-26)

| Item | Result |
|------|--------|
| Deployed commit | `82ea006659bb46d0cb017da53d207b65c4ab5e86` |
| Trigger | `main` push → `tests` success → `deploy-production` |
| `/up` | 200 |
| `/login` | 200 |
| `adman:production-check` | PASS |
| `adman:production-check --strict` | PASS |
| Horizon / scheduler | active |
| MySQL / Redis | localhost-only; health ok |
| Resend / OpenAI / WhatsApp config | present; WhatsApp enabled |
| `.env` | mode 600 preserved; untracked backups preserved |
| Private storage | present; HTTP 404 |
| Classification | **CI/CD READY** (PHPStan / Vue oxfmt+types still deferred from full `composer ci:check`) |

### Security posture during CI/CD

CI/CD must not weaken: key-only SSH, root SSH disabled, UFW, MySQL/Redis localhost-only, `.env` `600`, config cache least-privilege, `APP_DEBUG=false`, Horizon auth, `.git` / private storage not web-accessible.

---

## Task 030 — Production readiness & development-phase closure review (2026-09-28)

### Defect found and fixed: private-storage permissions (A — production blocker)

Flysystem's `local` disk creates directories `0700` owned by whichever process writes first. PHP-FPM (`www-data`) and Horizon/scheduler (`adman`, group `www-data`) therefore locked each other out:

- 2026-09-26: staff "email invoice" failed (`Unable to create a directory …/Invoice/2`) because the parent dir was worker-owned `0700`
- 2026-09-28: the worker created `…/Invoice/2` as `0700 adman` → PHP-FPM could not read INV-00002's PDF (staff download / secure link)
- `inbound/` media dirs created by FPM as `0700 www-data` → unreadable by workers

Fix:

| Layer | Change |
|-------|--------|
| App | `config/filesystems.php` local disk `permissions`: dirs `0770`, files private `0660` |
| Server | `UMask=0002` drop-ins for `php8.4-fpm`, `adman-horizon`, `adman-scheduler` (so `0770` is not masked to `0750`) |
| Existing dirs | Normalized by the deploy script (`chown -R adman:www-data`, dirs `775`) |
| Test | `tests/Feature/PrivateStoragePermissionsTest.php` |

If a new server is built, recreate the three drop-ins **before** go-live.

### Backups (B — operational risk, owner action)

Confirmed on the Droplet: **no** `mysqldump` cron/timer, **no** off-server copy, no backup files. Data today: MySQL ~200 MB on disk, `storage/app/private` ~2 MB. Smallest adequate options (owner choice, not implemented here):

1. Enable **DigitalOcean Droplet Backups** (console toggle; whole-disk, off-Droplet), and/or
2. A nightly `mysqldump` + `storage/app/private` tarball copied off-server (see `011-production-readiness.md` → Backup & recovery)

Before any deploy that includes a risky migration, take a manual dump first (the deploy script does not).

### Review summary

| Area | Result |
|------|--------|
| Production commit (before fix) | `33d8318` — matches `origin/main` |
| Full suite (local) | 208 tests: 206 passed, 2 skipped, 0 failed |
| Security | SSH key-only, root login off, UFW 22/80/443, MySQL/Redis `127.0.0.1`, `.env` 600, config cache 640, `APP_DEBUG=false`, `/horizon` 403, `/.git` + private storage 404, HTTP→HTTPS 301, Fail2ban active (52 bans), Certbot dry-run OK (cert valid to 2026-12-23) |
| Resources (1 GB) | ~485 Mi available, swap ~300 Mi but `si/so` ≈ 0 (no thrashing), load ~0.1, disk 22%, Horizon 0 restarts, 0 OOM, Redis 1.9 MB — **keep current Droplet** |
| Data integrity | invoice balance mismatches 0; claims all `pending_verification`; duplicate AI processing rows 0; duplicate provider ids 0; failed_jobs 0 |
| Integrations | Resend (2 emails `sent`, provider ids), OpenAI (24 completed processings), WhatsApp (26 real inbound, outbound `delivered`/`read`) |

Minor/deferred items (not closure blockers): no HSTS header; `robots.txt` allows indexing of `/login`; PHPStan + Vue `types:check`/oxfmt not in CI (pre-existing findings); `origin/develop` behind `main`; historical sections of this doc still describe earlier states (PHP 8.3, IP URL) by design.

---

## Task 031 — Admin verification, human-handoff visibility & WhatsApp formatting (2026-09-29)

Corrections from the live-test investigation (details in docs 001, 003, 008, 010):

| Item | Change |
|------|--------|
| Admin verification | Settings-created users are really pre-verified (`email_verified_at` was dropped by mass assignment). Data migration verified users with a `user.created` audit event (production user 2). |
| Take Over | Offered for AI-escalated (Human, unassigned) conversations; not offered on conversations owned by staff |
| Human attention | Dashboard card, Conversations filter `?attention=1`, badge + handoff reason (from audit) on the thread |
| Notification | One queued `ConversationNeedsHumanAttention` (mail + database) per AI → Human transition, to active users with `conversations.takeover` |
| WhatsApp text | `WhatsAppTextFormatter` applied to AI session text at delivery (links → bare URL, `**b**` → `*b*`, `*i*` → `_i_`, headings → bold); stored body unchanged; prompt asks for plain WhatsApp text |

Deployed `0a5d26e` via main → tests (run 36595340690) → deploy-production (run 36595780779). Local suite: 245 tests, 243 passed, 2 skipped (37 new tests).

Production verification: `/up` 200, `/login` 200, unsigned webhook POST 403, `adman:production-check` and `--strict` exit 0, Horizon running, scheduler timer active, MySQL/Redis ok, Resend API 200 (domain verified), OpenAI models API 200, WhatsApp config set, failed_jobs 0, no new errors in `laravel.log`. Both users verified. Formatter output checked against stored production AI bodies (no message sent). No real notification or WhatsApp message was triggered by verification.

Out of scope (unchanged): staff free-text WhatsApp replies, invitation onboarding, Return-to-AI behaviour (re-escalation noted in 010).

## Task 032 — Staff WhatsApp replies (2026-09-29)

Staff can send free-text WhatsApp replies from the conversation thread (details in docs 003, 008, 010). New authenticated route `POST /conversations/{conversation}/whatsapp-reply` (`messages.send`, CSRF, inside `auth` + `verified`); delivery reuses `WhatsAppOutboundService` → `SendOutboundWhatsAppJob` → Cloud API adapter. A reply is accepted only when the conversation is open, Human, assigned to the current user, and inside the 24-hour customer service window (latest stored inbound message for the identity). Staff text is sent as written; the AI formatter applies only to AI replies. No new queue, client, secret or public endpoint.

Deployed `5f92936` via main → tests (run 36602897025) → deploy-production (run 36603069496). Local suite: 256 tests, 254 passed, 2 skipped (11 new tests); frontend build ok.

Production verification: `/up` 200, `/login` 200, unsigned webhook POST 403, guest POST to the reply route 419 (CSRF), route registered, `adman:production-check` and `--strict` exit 0, `adman:health` database/redis/queue ok, Horizon running, scheduler timer active, failed_jobs 0, no new errors in `laravel.log`, Resend / AI / WhatsApp config present.

Controlled WhatsApp send: **not performed**. At verification time no WhatsApp conversation had an open 24-hour window (owner test conversation #5 window ended 2026-09-28 08:18 UTC) and all were in AI mode. A read-only production check confirmed the server-side gate refuses the reply with the expected reason. No conversation state was changed and no WhatsApp message was sent. To verify live: message the business number from the owner test phone, take over that conversation in ADMAN, send a short reply from the **WhatsApp reply** tab, and confirm the status moves to sent/delivered.

Not implemented (intentional): broadcast WhatsApp and email messaging, template messages.

## Task 033 — Communication lifecycle UAT (2026-09-29)

Defect fixed: `ConversationService::takeOver` reassigned a Human conversation already owned by another staff member on a direct or stale POST (UI never offered it), bypassing the Task 032 reply-ownership rule. Now an atomic conditional claim; refusal shown on the thread. Regression test added. Deployed `3b55eb4` via main → tests (run 36608244794) → deploy-production (run 36608401356). Local suite: 257 tests, 255 passed, 2 skipped; frontend build ok.

Controlled live UAT on the owner test conversation #5 only (times WAT):

| Step | Result |
|------|--------|
| Inbound "Hello, I need help" (18:59) | Stored, AI mode kept, AI reply sent → read |
| "I'd like to speak with the support team" (19:02) | AI handoff; Human + unassigned; reason recorded; 1 `ConversationNeedsHumanAttention` per eligible user (2); both emails delivered (Resend) |
| Dashboard card / Needs Attention filter / badge / reason / Take Over button | Confirmed in UI by owner |
| Take Over (Super Administrator) | Human, assigned; attention cleared; `conversation.taken_over` audited; no customer message. Second staff member refused for take over and reply (read-only service check) |
| Staff WhatsApp reply (19:12) | Staff message, provider id recorded, sent → read, received on phone as typed (URL + punctuation); `whatsapp.staff_reply_queued` + `whatsapp.sent`; no AI processing, no notification, no duplicate, still Human |
| Customer messages while Human | Stored, no AI processing |
| Return to AI → "What services do you offer?" | AI mode, unassigned; one AI answer (formatter applied at delivery), no re-escalation, no notification |

Observability: Horizon running, scheduler timer firing, failed_jobs 0, no new `laravel.log` lines during the UAT, queue empty, no outbound WhatsApp left pending/failed, `adman:production-check` and `--strict` exit 0, `adman:health` ok, `/up` and `/login` 200, unsigned webhook POST 403. No test data removed (the UAT messages are real history on the owner test conversation).

## Task 035 — Broadcast consent foundation (2026-09-30)

Consent fields on contacts (WhatsApp opt-in evidence, WhatsApp broadcast opt-out, email broadcast opt-in / unsubscribe, each with timestamp + controlled source), consent sections on contact create/edit (`contacts.update`, server-side), `contact.consent_changed` audit, and `BroadcastEligibilityService` (WhatsApp + email). No broadcast engine, template sending, unsubscribe endpoint or provider webhooks. Transactional sends unchanged. Details in 002 → Consent, 007, 008.

Deployed `3d0db7d` via main → tests (run 36702789238) → deploy-production (run 36702958586). Migration `2026_09_30_100000_add_broadcast_consent_to_contacts` ran (nullable columns only, reversible). Local suite: 275 tests, 273 passed, 2 skipped (18 new); frontend build ok.

Production data safety (read-only): 2 contacts; all 8 consent columns null; `whatsapp_opt_in` unchanged (both false, rows last updated 2026-09-26); 0 `contact.consent_changed` events; 0 contacts broadcast-eligible on either channel. No messages sent.

Verification: `/up` and `/login` 200, `adman:production-check` and `--strict` exit 0, `adman:health` ok, Horizon running, scheduler timer active, failed_jobs 0, no new `laravel.log` errors (latest entries 2026-09-26).

Next engineering task (before any broadcast execution): approved transactional WhatsApp template path. Configured template names do not exist in Meta, so document sends outside the 24-hour window can fail (008 → Known gap).

## Task 036 — Transactional WhatsApp 24-hour window safety (2026-09-30)

Quote, invoice, invoice reminder and payment acknowledgement WhatsApp sends now decide the delivery mode at queue and send time: window open → PDF document message (unchanged); window closed → approved Utility template with the PDF as a DOCUMENT header, but only when `WHATSAPP_TEMPLATE_<KEY>_ENABLED=true` and a name is set; otherwise blocked with a staff-facing reason and a `whatsapp.blocked` audit (reminders become Not deliverable). No free-form fallback, no retry loop. Clearer Meta error mapping (window, missing/invalid/paused/disabled template, opt-out, account restriction). Transactional gate remains `whatsapp_opt_in`; broadcast consent does not affect it. Details in 008 → Templates and 009.

Deployed `6bae031` via main → tests (run 36708281527: 289 passed, 2 skipped) → deploy-production (run 36708435698). No migrations. Local suite: 291 tests, 289 passed, 2 skipped (16 new), run as `php -d memory_limit=1G vendor/bin/pest` — the default 128M local CLI limit is exhausted by cumulative PDF generation across the single-process suite (CI has no limit).

Production state: all four template names configured in env, all `_ENABLED` flags false → no template resolves; out-of-window document sends are blocked until the owner's Meta templates are APPROVED. Outbound messages unchanged (43, latest 2026-09-29 19:18), 0 `whatsapp.blocked` audits. No messages sent.

Verification: `/up` and `/login` 200, `adman:production-check` and `--strict` exit 0, `adman:health` ok, Horizon running, scheduler timer active, failed_jobs 0, no `laravel.log` errors on 2026-09-30.

Next engineering task: once Meta approves the four Utility templates, a controlled production enablement and verification (owner test number, outside the window) before broadcasts.

## Task 037 — Meta template enablement & controlled UAT (2026-09-30) — STOPPED AT OWNER PREREQUISITE

Read-only Graph API check (`message_templates`, 12:40 WAT): the WhatsApp Business Account has only `hello_world` (Utility, `en_US`, APPROVED). None of `adman_quote`, `adman_invoice`, `adman_invoice_reminder` or `adman_payment_ack` exists in any state. This meets the task's stop condition, so nothing was enabled, no production configuration changed, and no production UAT messages were sent.

Production configuration (unchanged, running `6bae031`): the four names and `WHATSAPP_TEMPLATE_LANGUAGE=en` are set; there are no `_ENABLED` or per-template `_LANGUAGE` keys, so all four resolve as disabled. Outbound messages unchanged (43), 0 pending, 0 duplicate provider message IDs, 0 `whatsapp.blocked` audits.

Verification: `/up` and `/login` 200, `adman:production-check` and `--strict` exit 0, `adman:health` ok, Horizon running, scheduler timer active, failed_jobs 0, no `laravel.log` errors on 2026-09-30. Focused tests 45/45 plus eligibility 3/3; full suite re-run on unchanged application code `6bae031`: 291 tests, 289 passed, 2 skipped.

Remaining owner action: create and get approval for the four Utility templates exactly as in 008 → Templates (Document header, 3 or 5 body variables, no buttons, language `en` or matching `_LANGUAGE`). Then re-run Task 037.

## Task 037R — Re-run of template enablement (2026-09-30 14:24) — STOPPED AT TEMPLATE MISMATCH

Read-only Graph API check: four new templates are approved in Meta, all Utility and language `en`: `quote_sent`, `invoice_sent`, `invoice_reminder` and `payment_acknowledgement`. **None has a header component** (so there's no Document header), and their body variables don't match what ADMAN sends. They use 5 or 6 variables (business name, amount, dates, status) with no secure link; ADMAN sends a PDF document header plus 3 variables, or 5 for the reminder. Every send would be rejected by Meta (`132000`), and the PDF could not be attached. This meets the stop conditions "document header unavailable" and "template variables do not match", so nothing was enabled, production `.env` is unchanged, and no live UAT was sent. Details in 008 → Templates → Current state.

Production (`6bae031`, no deploy): outbound messages unchanged (43), 0 pending, 0 duplicate provider IDs, 0 `whatsapp.blocked` audits; `/up` and `/login` 200, `adman:production-check` and `--strict` exit 0, `adman:health` ok, Horizon running, scheduler timer active, failed_jobs 0, no `laravel.log` errors on 2026-09-30. No application code changed.

Remaining: an approved Document header on each template, plus either template bodies aligned to ADMAN's variables or an engineering change to ADMAN's per-template variable mapping.

## Task 037R2 — Corrected-template verification (2026-09-30 15:17) — STOPPED, TEMPLATES STILL MISMATCHED

Read-only Graph API check:
- **`quote_sent`** (approved, Utility, `en`) now has a Document header and 3 body variables. But {{3}} is the business name ("Your quote {{2}} from {{3}} is ready."); ADMAN sends the secure link there.
- **`invoice_sent`, `invoice_reminder`, `payment_acknowledgement`** are unchanged since 037R: no header, 5, 6 and 5 variables. No pending edits were visible.

No template matches ADMAN's interface, so nothing was enabled, production `.env` is unchanged, and no live UAT was sent. ADMAN's variable mapping was not changed (Meta templates are to conform to the tested interface). Details in 008 → Templates → Current state.

Production (`6bae031`, no deploy, no code change): outbound messages unchanged (43), 0 pending, 0 duplicate provider IDs, 0 `whatsapp.blocked` audits; `/up` and `/login` 200, `adman:production-check` and `--strict` exit 0, `adman:health` ok, Horizon running, scheduler timer active, failed_jobs 0, no `laravel.log` errors on 2026-09-30.

Remaining owner action:
- `quote_sent`: make {{3}} the secure document link.
- The other three: add a Document header and use ADMAN's variables (008 → Templates).
- Get all four re-approved, then re-run 037R2.

## Task 037R2 (continued, 2026-10-01) — quote live UAT; BLOCKED by Meta account payment method

Meta at 10:26 WAT:
- `quote_document` (new name) and `invoice_sent`: APPROVED, Utility, `en`, Document header, 3 variables, matching ADMAN;
- `invoice_reminder` and `payment_acknowledgement`: correct structure, **PENDING**.

Production config change (server `.env` only, backups in `/home/adman/.env.bak-037r2-*`, mode 600):
- `WHATSAPP_TEMPLATE_QUOTE=quote_document`, `WHATSAPP_TEMPLATE_QUOTE_LANGUAGE=en`;
- `WHATSAPP_TEMPLATE_QUOTE_ENABLED=true` for the test, then set back to `false`;
- config cache rebuilt at 640, PHP-FPM reloaded, Horizon restarted.

No code change, no deploy (still `6bae031`).

Owner actions in the UI (audited as Super Administrator):
- on the owner's own contact: WhatsApp opt-in turned on (also email broadcast opt-in, and reminder channel changed to "both");
- issued test quote QT-00001;
- one **Send on WhatsApp** with the window closed.

Result: message #121 was `document_template` with `quote_document`. The parameters were correct, with the `QT-00001.pdf` header. Meta accepted it (`wamid…` recorded, `whatsapp.queued`/`whatsapp.sent` audits), then the status webhook failed it with `131042` ("account payment issue"). There was no retry and no duplicate. The WABA `health_status` confirms `can_send_message: BLOCKED`, error `141006` (payment method). The invoice template was not enabled; the reminder and payment acknowledgement templates are pending.

Finding: the contact phone is stored in local format (`070…`), so ADMAN created a second WhatsApp identity, separate from the existing `234…` one. Window detection for that contact uses the wrong identity. The fix is data: store the number with the country code.

Safety: no messages to customers; 0 payments; invoice balances and statuses unchanged; 0 duplicate provider IDs; no broadcast tables. Health: `/up` and `/login` 200, production-check and `--strict` exit 0, `adman:health` ok, Horizon running, scheduler active, failed_jobs 0, no `laravel.log` errors.

## Task 037R2 — closure (2026-10-01) — CLOSED / PASS

The owner fixed the Meta account payment method (WABA `health_status.can_send_message` is now `AVAILABLE`), and Meta approved `invoice_reminder` and `payment_acknowledgement`. Each template was then enabled on its own and tested live once with the window closed, followed by one in-window regression. All sends went to the owner's own test number and were triggered by the owner from the normal ADMAN pages (the reminder by the daily `reminders:process-due` command). Results, message IDs and details: 008 → Templates → Production UAT.

| Scenario | Mode | Message | Result |
| --- | --- | ---: | --- |
| Quote, window closed | `quote_document` template | #123 | delivered, PDF header, link 200 |
| Invoice, window closed | `invoice_sent` template | #124 | delivered, PDF header, link 200 |
| Reminder, window closed | `invoice_reminder` template | #125 | delivered, PDF header, link 200 |
| Payment acknowledgement, window closed | `payment_acknowledgement` template | #126 | delivered, PDF header, link 200 |
| Invoice, window open (after inbound #127) | direct `document_pdf` | #128 | delivered, PDF document, link 200 |

Final WhatsApp production state:
- Meta/WABA connection and the production number are operational.
- All four Utility templates are approved (`en`, Document header, no buttons) and enabled; the old `adman_*` placeholder names are no longer in `.env` or the cached config.
- The 24-hour window choice works both ways: direct document inside the window, template fallback outside it.
- Provider IDs and statuses are recorded on every message; `whatsapp.queued` / `whatsapp.sent` audits are present.
- Secure links work, and each send rotates the link (the previous one returns 404).
- The in-window caption has no secure link. This is existing design, not a regression (008 → Outbound lifecycle).

Production configuration (server `.env` only; backups in `/home/adman/.env.bak-037r2-*`, mode 600): `WHATSAPP_TEMPLATE_<KEY>` set to the approved names, `_LANGUAGE=en` and `_ENABLED=true` for `QUOTE`, `INVOICE`, `INVOICE_REMINDER` and `PAYMENT_ACK`. After each change: config cache rebuilt (`config.php` `adman:www-data` 640, `.env` 600), PHP-FPM reloaded, Horizon restarted. No code change and no deploy: production still runs `6bae031`.

Data changes during the UAT (owner actions through the UI, all audited):
- the test contact's phone was corrected to the `+234…` format, and its reminder channel is `both`;
- QT-00001 was issued (₦57,000);
- a genuine ₦1,000 payment, RCPT-00001, was recorded and confirmed on INV-00002 (now partially paid, ₦56,000 outstanding). There is no reversal process, so it stays;
- the test conversation was taken over by staff before the in-window test.

Messages #121 and #122 failed with `131042` while the Meta payment method was blocked. Message #120 is the normal 07:00 scheduled overdue-reminder email for INV-00002 to the same test contact.

Safety (verified 18:30 WAT):
- 1 payment (confirmed), 3 payment claims, other invoices unchanged;
- WhatsApp opt-in still on, no broadcast consent or opt-outs, no broadcast tables;
- 0 duplicate provider IDs, 0 stuck messages, 0 `whatsapp.blocked` audits.

Health: `/up` and `/login` 200, `adman:production-check` and `--strict` exit 0, database, Redis and queue ok, Horizon running, scheduler timer active, failed_jobs 0, no `laravel.log` errors on 2026-10-01. Local full suite on the unchanged code (`php -d memory_limit=1G vendor/bin/pest`): 291 tests, 289 passed, 0 failed, 2 skipped, 1,411 assertions; `npm run build` succeeds.

Follow-up items (not implemented): local phone-number normalization; optional secure link in the in-window caption; see 008 → Post-037R2 follow-up items. Broadcasts start with Task 038.

## Task 038 — Broadcast foundation (2026-10-02) — DEPLOYED, BROADCASTS DISABLED

Commit `35edb5d` was pushed to `main`. The CI `tests` run and `deploy-production` (run 37009119450) both succeeded. Architecture, gates and the enablement/UAT procedure: [017-broadcasts.md](017-broadcasts.md).

Migrations applied on production (all additive and reversible):
- `2026_10_02_100000_add_whatsapp_broadcast_opt_in_to_contacts`
- `2026_10_02_110000_create_broadcasts_tables`
- `2026_10_02_120000_add_broadcast_permissions`

The permissions migration creates `broadcasts.manage` and `broadcasts.send` and grants them to Super Administrator.

Production state after the deploy (verified at 13:52 WAT, read-only):
- `businesses.broadcasts_enabled = 0`; it was **not** enabled.
- The `broadcasts` and `broadcast_recipients` tables are empty.
- 0 contacts have a WhatsApp broadcast opt-in, and 0 have an email broadcast unsubscribe.
- `WHATSAPP_TEMPLATE_BROADCAST*` is not set, so the broadcast template is disabled and has no name. Meta has no approved Marketing template (`MARKETING_COUNT=0` at the Task 038 read-only check), so WhatsApp broadcasts are blocked by Meta until the owner creates and approves one.
- Unchanged:
  - RCPT-00001: ₦1,000, confirmed.
  - INV-00002: partially paid, ₦56,000 outstanding.
  - Other invoices are unpaid.
  - Conversation #7 is `human`, assigned to user 1.
  - The test contact's reminder channel is `both`.

Health: `/up` and `/login` 200, `adman:production-check` and `--strict` exit 0, database, Redis and queue ok, Horizon running, scheduler timer active, queue size 0, failed_jobs 0, no new `laravel.log` errors since 2026-09-26.

Local verification before the push:
- focused tests: `BroadcastTest` 41/41 and `ContactConsentTest` 18/18;
- full suite: 332 tests, 330 passed, 2 skipped, 1,634 assertions;
- `npm run build` succeeds.

No broadcast was created or sent, and no messages were sent to customers.

## Task 039 — Controlled broadcast UAT (2026-10-02) — BLOCKED, NOTHING SENT

Production still runs `35edb5d`. No code change, no deploy, no configuration change. `broadcasts_enabled` was never switched on and is still `0`.

Read-only preflight at 14:10 WAT:
- **Meta:** HTTP 200. The only templates are the five Utility templates plus `hello_world`, all UTILITY / APPROVED; `MARKETING_COUNT=0`.
  - **WhatsApp UAT: BLOCKED.** No approved Marketing template exists in Meta.
- **Consent:**
  - Contact #1 is archived.
  - Contact #2 (the owner's test contact) has a valid email and a transactional WhatsApp opt-in, but no email broadcast opt-in and no WhatsApp broadcast opt-in.
  - No consent was created for the test.
  - **Email UAT: BLOCKED.** It requires a real, explicit broadcast opt-in before a message can be sent.
- **Broadcast data:** 0 broadcasts and 0 recipients. `broadcasts.manage` and `broadcasts.send` are on Super Administrator only.
- **Unchanged:**
  - RCPT-00001: ₦1,000, confirmed. INV-00002: partially paid, ₦56,000 outstanding. INV-00001/3/4: unpaid.
  - QT-00001: issued. The 3 payment claims are still `pending_verification`.
  - Conversation #7: `human`, assigned to user 1. Contact #2's reminder channel: `both`.
  - The four transactional Utility templates are still enabled (`en`). The mailer is `resend`.

Unsubscribe mechanism, checked without sending: a URL generated on production is `https://adman.raslordeckltd.com/email/unsubscribe/{contact}/{broadcast}?signature=…`. It validates as signed, and changing the contact ID invalidates it. The signature was not recorded.

Safety after the checks:
- messages max ID still 128;
- 0 duplicate `external_message_id`, 0 pending/processing messages;
- failed_jobs 0, queue size 0.

Health: `/up` and `/login` 200, `adman:production-check` and `--strict` exit 0, database, Redis and queue ok, Horizon running, scheduler timer active, 0 `laravel.log` errors on 2026-10-02. Memory 961 MB total, 405 MB available, swap 195 MB used; disk 22% used (5.1 GB of 24 GB).

Local regression: 332 tests, 330 passed, 2 skipped, 1,634 assertions; `npm run build` succeeds.

To unblock:
- **Email:** the owner records a genuine email broadcast opt-in for a deliberate test contact (contact form → Broadcast consent), then reruns the UAT.
- **WhatsApp:** additionally needs a Meta-approved Marketing template whose body has no parameters or only `{{1}}` = contact name, configured through `WHATSAPP_TEMPLATE_BROADCAST*`. The contact also needs a WhatsApp broadcast opt-in.

## Task 040 — WhatsApp phone normalization (2026-10-02) — DEPLOYED

Commit `e7a4fd6` was pushed to `main`. The CI `tests` run (37017883460) and `deploy-production` (37018085239) both succeeded. There are no migrations and no schema or data changes. Details: 008 → Identity normalization.

What changed:
- `WhatsAppPhone::normalize` converts Nigerian local mobile numbers (`07054998090`) and the `+234 (0)…` form to the canonical `2347054998090`.
- A `creating` hook on `CommunicationIdentity` stores new WhatsApp identities in canonical form. The existing unique `(channel, external_id)` index then blocks equivalent duplicates.

Preflight (read-only) found two legacy duplicate pairs, created before the fix:

| Canonical identity | Legacy duplicate |
| --- | --- |
| #7 `2347054998090` (Taribi Isaac, 31 messages) | #12 `07054998090` (1 failed quote message, #121) |
| #5 `2349067322344` (unlinked, 36 messages) | #6 `09067322344` (1 recorded staff message) |

They were reported and left untouched. A cleanup proposal is in 008.

Post-deploy verification at 15:30 WAT:
- **Resolution:** `07054998090`, `+2347054998090`, `0705 499 8090` and `2347054998090` all resolve to identity #7 (contact 2, active, 31 messages). `09067322344` resolves to #5.
- **No new data:** identities still 12 (max ID 12); messages 128 (max ID 128); conversations 12.
- Contact 2's phone is unchanged (`+2347054998090`) and its reminder channel is still `both`.
- **Unchanged:**
  - RCPT-00001: confirmed at ₦1,000. INV-00002: partially paid, ₦56,000 outstanding.
  - The 3 payment claims are still `pending_verification`. QT-00001: issued.
  - Conversation #7: `human`, assigned to user 1.
  - `broadcasts_enabled = 0`.
- **Health:** `/up` and `/login` 200; `adman:production-check` and `--strict` exit 0; database, Redis and queue ok; Horizon running; scheduler timer active; failed_jobs 0; queue size 0; 0 `laravel.log` errors today. Memory: 443 MB available; disk 22% used.

Local verification: `WhatsAppPhoneNormalizationTest` 10/10 (8 of them fail on the previous code); full suite 342 tests, 340 passed, 2 skipped, 1,687 assertions; `npm run build` succeeds. No WhatsApp message was sent.

## Task 041 — Marketing template + controlled WhatsApp broadcast UAT (2026-10-02) — PASS, SWITCH OFF

No code change and no deploy: production still runs `e7a4fd6`. Template details and full results: 017 → Production Marketing template / Production UAT.

**Meta (read-only, HTTP 200):**
- `broadcast_test` is MARKETING, APPROVED, `en`.
- BODY only (no header, footer or buttons), with exactly one variable, `{{1}}`. The text matches the owner's brief.
- It is the only Marketing template; the Utility templates are unchanged.

**Configuration (server `.env` only):**
- Backup: `/home/adman/.env.bak-041-20261002-154155` (mode 600).
- Added `WHATSAPP_TEMPLATE_BROADCAST=broadcast_test`, `_LANGUAGE=en`, `_ENABLED=true`, `_PARAMETERS=contact_name`.
- Config cache rebuilt (`adman:www-data` 640, `.env` 600), PHP-FPM reloaded, Horizon restarted.
- The cached config resolves to the same values. The four transactional templates are unchanged (names, `en`, enabled).

**Consent:** recorded by the owner before the task (staff-recorded, audited 14:37 and 14:40 WAT). It was not modified.

The test contact (#2):
- is a customer and not archived;
- has a valid email;
- has email broadcast opt-in on, with no unsubscribe;
- has WhatsApp broadcast opt-in on, with no opt-out;
- has an active canonical identity, #7.

**Preview (unsaved):**
- Selected contact #2: 1 eligible, 0 excluded, template `broadcast_test` / `en`. The only blocker was the business switch.
- The Customers audiences would also have produced only contact #2.

**Live UAT:** at 15:43:38 WAT the switch was turned on (audited), and broadcast #1 was created and started with a confirmed count of 1. The switch was turned off in the same second (audited); it is only read at preview and start time.

Outcome:
- Broadcast #1: `completed` at 15:43:42.
- Recipient #1: `delivered` at 15:43:50.
- Message #129: `broadcast_template`, `broadcast_test` / `en`, body parameter = the contact's name, one wamid, stored in existing conversation #7. The conversation stayed `human`, assigned to user 1.

After the send:
- 1 broadcast, 1 recipient, 1 new message; 0 duplicate provider IDs; nothing stuck; failed_jobs 0; queue 0.
- Unchanged: RCPT-00001 (₦1,000, confirmed); INV-00002 (partially paid, ₦56,000 outstanding); other invoices; the 3 claims (`pending_verification`); QT-00001 (issued); contact #2's reminder channel (`both`).
- No email broadcast was sent.

Health: `/up` and `/login` 200; `adman:production-check` and `--strict` exit 0; database, Redis and queue ok; Horizon running; scheduler timer active; 0 `laravel.log` errors today. Memory: 424 MB available; disk 22% used.

Local regression after the UAT (no code change): 342 tests, 340 passed, 2 skipped, 1,687 assertions; `npm run build` succeeds.

**Final switch state:** `broadcasts_enabled = 0`. The template configuration stays enabled; on its own it sends nothing.

## Task 042 — Controlled email broadcast UAT (2026-10-02) — PASS, SWITCH OFF

No code, configuration or deploy change: production still runs `e7a4fd6`. Details: 017 → Email broadcast UAT.

**Preflight (read-only):**
- `broadcasts_enabled = 0`; outbound email enabled; mailer `resend` with key and sender set (values not printed).
- Recent transactional emails were `sent`. Horizon running; failed_jobs 0; queue 0.
- The owner account has `broadcasts.manage` and `broadcasts.send`.
- Contact #2 is a customer, not archived, with a valid email, email broadcast opt-in recorded by staff (14:40 WAT) and no unsubscribe. Selected-contact preview: 1 eligible, 0 excluded. The only blocker was the switch.

**Content:** the body was only the two approved paragraphs, because the broadcast template adds "Hello <name>," itself and has no placeholder substitution. The email was rendered on production without sending and reviewed: subject, greeting with the contact name, body, sign-off, unsubscribe footer, `List-Unsubscribe` headers, no attachment.

**Live UAT:** at 16:45:56 WAT the switch was turned on (audited), and broadcast #2 was created and started with a confirmed count of 1. The switch was turned off in the same second (audited).

Outcome:
- Broadcast #2: `completed` at 16:45:58.
- Recipient #2: `sent`.
- Message #130: email, `template_key = broadcast`, no document, in the contact's email conversation #8. Stored provider ID `laravel-mail-130`, the existing behaviour for all emails.
- Resend (read-only API): email `01a0fd4b-2f9a-70a5-9c0f-4b822771a365`, `last_event = delivered`. It is the only email with this subject.

**Safety:**
- 2 broadcasts and 2 recipients in total; 1 new message; 0 duplicate provider IDs or recipients; nothing stuck; failed_jobs 0; queue 0.
- No WhatsApp message.
- Unchanged: RCPT-00001 (₦1,000, confirmed); INV-00002 (partially paid, ₦56,000 outstanding); the 3 claims (`pending_verification`); QT-00001 (issued); reminder occurrences; contact #2 (reminder channel `both`, no unsubscribe); conversation #7 (`human`, user 1).
- The WhatsApp broadcast and transactional template configuration is unchanged.

Health: `/up` and `/login` 200; `adman:production-check` and `--strict` exit 0; database, Redis and queue ok; Horizon running; scheduler timer active; 0 `laravel.log` errors today. Memory: 414 MB available; disk 22% used.

Local regression: 342 tests, 340 passed, 2 skipped, 1,687 assertions; `npm run build` succeeds.

**Final switch state:** `broadcasts_enabled = 0`.

## Task 043 — Broadcast readiness review (2026-10-02) — READY, SWITCH OFF

A read-only review: no code, configuration, data or deploy change, and no broadcast sent. Production still runs `e7a4fd6`.

**Configuration and Meta:**
- `WHATSAPP_TEMPLATE_BROADCAST` = `broadcast_test` / `en` / enabled / `contact_name` (`.env` and cached config agree).
- Meta re-check (HTTP 200): `broadcast_test` is MARKETING, APPROVED, `en`, BODY only, one variable `{{1}}`.
- It is the UAT template; replace it before a real campaign (017 → UAT configuration vs production campaign configuration).
- The four transactional Utility templates are unchanged (approved, `en`, enabled). Resend is configured.

**Data unchanged during the review:**
- `broadcasts_enabled = 0`; broadcasts #1 and #2 and their recipients untouched.
- Messages max ID 130; audit events max ID 379; failed_jobs 0; queue 0.
- Payments, invoices, claims and contacts unchanged.

Tests: `BroadcastTest` and `ContactConsentTest` 59/59; full suite 342 tests, 340 passed, 2 skipped, 1,687 assertions; `npm run build` succeeds.

Health: `/up` and `/login` 200; `adman:production-check` and `--strict` exit 0; database, Redis and queue ok; Horizon running; scheduler timer active; 0 `laravel.log` errors today. Memory: 414 MB available; disk 22% used.

## Task 045 — Production WhatsApp broadcast template configured (2026-10-02) — SWITCH OFF

No code change and no deploy: production still runs `e7a4fd6`. Task 044 stopped because the template did not exist yet.

**Meta (read-only, HTTP 200):** `raslordeck_broadcast` is MARKETING, APPROVED, `en`. BODY only (no header, footer or buttons), with exactly one variable, `{{1}}`. The body matches the intended wording exactly. This is compatible with ADMAN, whose only parameter is `contact_name` → `{{1}}`.

**Configuration (server `.env` only):**
- Backup: `/home/adman/.env.bak-045-20261002-181725` (mode 600).
- Changed `WHATSAPP_TEMPLATE_BROADCAST` from `broadcast_test` to `raslordeck_broadcast`. `_LANGUAGE=en`, `_ENABLED=true` and `_PARAMETERS=contact_name` are unchanged.
- Config cache rebuilt (`adman:www-data` 640, `.env` 600), PHP-FPM reloaded, Horizon restarted.

**Verification:**
- `.env`, cached config, `WhatsAppBroadcastTemplate` and an unsaved broadcast preview all resolve to `raslordeck_broadcast` / `en` / `contact_name`.
- No `broadcast_test` reference remains in `.env`, the cached config or the application code. `broadcast_test` stays approved in Meta (not deleted).
- The preview still reports "Broadcasts are turned off in Business settings."
- The four transactional templates are unchanged: Utility, approved, `en`, enabled, DOCUMENT header.

**Data unchanged:**
- `broadcasts_enabled = 0`; 2 broadcasts and 2 recipients (the UAT records).
- Messages max ID 130; audit events max ID 379.
- Consent unchanged (1 WhatsApp and 1 email broadcast opt-in); `broadcasts.send` still held only by Super Administrator.
- Payments, invoices, claims and quote unchanged; failed_jobs 0; queue 0.

Tests: `BroadcastTest` and `ContactConsentTest` 59/59; full suite 342 tests, 340 passed, 2 skipped, 1,687 assertions; `npm run build` succeeds.

Health: `/up` and `/login` 200; `adman:production-check` and `--strict` exit 0; database, Redis and queue ok; Horizon running; scheduler timer active; 0 `laravel.log` errors today. Memory: 420 MB available; disk 22% used.

No broadcast was created or sent.

## Task 046 — WhatsApp broadcast campaign message (2026-10-03) — DEPLOYED, TEMPLATE NOT ACTIVATED

Code `294116b`, deployed by CI (`tests` then `deploy-production`, both success). Details: `017-broadcasts.md` § WhatsApp campaign message.

- **Migration:** `2026_10_02_130000_add_whatsapp_message_to_broadcasts` ran (nullable `broadcasts.whatsapp_message`). Existing broadcasts #1 and #2 keep null and stay readable.
- **Meta (read-only, HTTP 200):** `raslordeck_broadcast_v2` does **not** exist (templates: `raslordeck_broadcast`, `broadcast_test`, the Utility templates, `hello_world`). The configuration switch was therefore **not** made.
- **Configuration unchanged:** `.env` and cached config still `raslordeck_broadcast` / `en` / enabled / `contact_name`. `WhatsAppBroadcastTemplate` resolves to it with no problem and no campaign message (the Message field is hidden; a message is refused).
- **New read-only Meta call:** the broadcast Show page now reads the configured template's approved body text (`GET /{WABA}/message_templates`, cached 10 min) to render the preview. Verified in production: the `raslordeck_broadcast` body is found and renders correctly. It never sends anything and is not part of the send path.
- **Activation later (owner):** create `raslordeck_broadcast_v2` (Marketing, `en`, body only, `{{1}}` name, `{{2}}` message). After Meta approves it: back up `.env`, set `WHATSAPP_TEMPLATE_BROADCAST=raslordeck_broadcast_v2` and `WHATSAPP_TEMPLATE_BROADCAST_PARAMETERS=contact_name,broadcast_message` (language `en`, enabled `true`), rebuild the config cache (`adman:www-data` 640), reload PHP-FPM, restart Horizon, and verify. Keep `broadcasts_enabled=false`.

**Data unchanged:**
- `broadcasts_enabled = 0`; 2 broadcasts, 2 recipients.
- Messages max ID 130; audit events max ID 379.
- RCPT-00001 ₦1,000 confirmed; INV-00002 partially paid; 3 claims pending verification; QT-00001 issued.
- The four transactional templates are unchanged (`quote_document`, `invoice_sent`, `invoice_reminder`, `payment_acknowledgement`: `en`, enabled).

Tests: `BroadcastTest` 58/58 (17 new); full suite 359 tests, 357 passed, 2 skipped, 1,849 assertions; `npm run build` succeeds; type-check error count unchanged (33, pre-existing, none in broadcast files).

Health: `/up` 200; `adman:production-check` and `--strict` exit 0; database, Redis and queue ok; Horizon running; scheduler timer active; failed jobs 0; 0 `laravel.log` errors today.

No broadcast was created or sent.

---

## Approval reminders (from Task 016)

- 1 GB Droplet with capped Horizon — **done (maxProcesses=1)**  
- Confirm DNS authority before A-record change — **done (Go54)**  
- Fail2ban: yes (done in Task 017)  
- Publish `develop` on GitHub when ready (no staging deploy yet)  
