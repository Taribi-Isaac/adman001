# 016 — Production Deployment

Operational runbook for ADMAN production on DigitalOcean.

Related: Task 016 architecture review; Task 011 production readiness; Task 012 staging (future).

---

## Status (Task 026) — Web runtime restored; Meta webhook GET ready

| Item | State |
|------|--------|
| Droplet | `165.232.103.182` (`lon1`), hostname `adman-prod` |
| App | `/var/www/adman` @ `main` |
| Production URL | **`https://adman.raslordeckltd.com`** |
| TLS | Let's Encrypt active; HTTP→HTTPS |
| Horizon | **systemd `adman-horizon.service`** — 1 worker |
| Runtime user | `adman` (group `www-data`) — not root |
| Config cache | `bootstrap/cache/config.php` `adman:www-data` **640**; `.env` **600** |
| `adman:production-check --strict` | **PASS** |
| Email | **Resend active** |
| AI | **OpenAI active** |
| WhatsApp | **Disabled** (`ADMAN_WHATSAPP_ENABLED=false`); Meta GET handshake verified server-side; App Secret / Access Token / Phone Number ID still missing |

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
| Backups | Optional (deferred automated app backups remain out of scope; DO backups are separate) |
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

## Next step

Retry Meta **Verify and save** with Callback URL `https://adman.raslordeckltd.com/webhooks/whatsapp` and the existing production verify token. Then supply App Secret + Access Token + Phone Number ID before setting `ADMAN_WHATSAPP_ENABLED=true` and running Task 025 E2E.

---

## Approval reminders (from Task 016)

- 1 GB Droplet with capped Horizon — **done (maxProcesses=1)**  
- Confirm DNS authority before A-record change — **done (Go54)**  
- Fail2ban: yes (done in Task 017)  
- Publish `develop` on GitHub when ready (no staging deploy yet)  
