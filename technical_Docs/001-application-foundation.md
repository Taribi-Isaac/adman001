# ADMAN Technical Documentation — Application Foundation

## Overview

ADMAN is a Laravel modular monolith for single-business administration and automation.

This document describes the foundation established in Task 001.

## Technology choices

| Layer | Choice |
| --- | --- |
| Backend | Laravel 13 (PHP 8.3+) |
| Frontend | Inertia.js 3 + Vue 3 + TypeScript |
| CSS | Tailwind CSS 4 with semantic design tokens |
| Auth | Laravel Fortify (session auth, no public registration) |
| RBAC | Spatie Laravel Permission (permission-based) |
| Database | MySQL (business source of truth) |
| Cache / queues / sessions | Redis |
| Queue monitoring | Laravel Horizon |
| Scheduler | Laravel Scheduler (`routes/console.php`) |
| Tests | Pest / PHPUnit |

## Application structure

```text
app/
  Console/Commands/     # adman:create-super-admin
  Http/Controllers/     # Settings, foundation controllers
  Models/               # User, Business, AuditEvent
  Services/             # AuditLogger
  Support/              # Permissions constants
  Providers/            # App, Fortify, Horizon
resources/js/
  pages/                # Inertia pages
  components/           # UI + app shell
  layouts/              # App + settings layouts
technical_Docs/         # Engineering documentation
foundational_docs/      # Approved product documents (source of truth)
```

Domain modules (Contacts, Documents, Payments, Automation, AI, Communication, Recurring Billing) live under `app/` and `resources/js/pages/` without microservices.

Contacts domain documentation: `technical_Docs/002-contacts-domain.md`.


## Authentication

- Staff-only authentication via Fortify.
- Public registration is **disabled**.
- Customers do **not** have application accounts.
- Password reset, email verification, 2FA, and passkeys remain available for staff.
- Inactive users (`users.is_active = false`) are logged out by `EnsureUserIsActive`.

## Super Administrator bootstrap

Never commit credentials.

```bash
php artisan migrate --seed
php artisan adman:create-super-admin --name="Admin" --email="admin@example.com"
# password is prompted securely if --password is omitted
```

The command ensures roles/permissions exist, verifies the email, assigns the `Super Administrator` role, and writes an audit event.

## RBAC

- Permissions are named capabilities (e.g. `business.update`, `users.view`).
- Roles currently seeded:
  - **Super Administrator** — all foundation permissions (also bypasses Gate checks)
  - **Staff** — `settings.access`, `business.view`
- Permission checks use Spatie middleware / `$user->can(...)`.
- Do **not** hard-code business behaviour around role names when a permission exists.

Foundation permissions live in `App\Support\Permissions`.

## Business / Organization settings

- Single `businesses` row per deployment (`Business::current()`).
- Not multi-tenant.
- Configurable fields include identity, address, tax/VAT, currency, **timezone**, bank/payment instructions, document prefixes, default terms, and **branding (logo)**.
- Logo upload lives under Settings → Business → Branding (`logo_path` on `local` private disk). When unset, PDFs and settings preview fall back to `public/ADMAN-LOGO.png`.
- Business timezone is applied at application boot when available (`AppServiceProvider`).

## Dashboard

- Route: `GET /dashboard` → `DashboardController`.
- Shows a small operational “what needs attention today” view (outstanding/overdue invoices, pending payment claims, issued quotes, reminder attention, recent audit activity when permitted).
- Not an analytics platform.

## Audit foundation

- Table: `audit_events`
- Service: `App\Services\AuditLogger`
- Records actor, event name, description, auditable morph, old/new values, meta, IP, user agent.
- Foundation currently audits: super-admin creation/promotion, business setting updates, user create/update.
- Future domains should reuse `AuditLogger` rather than inventing a second audit store.

## Queue / cache / scheduler

Recommended local `.env`:

```env
DB_CONNECTION=mysql
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_CLIENT=phpredis
```

Run workers:

```bash
php artisan horizon
# or
php artisan queue:work

php artisan schedule:work
```

Horizon access is gated by the `system.horizon` permission (Super Administrators included).

Scheduler currently runs Horizon snapshots and business jobs registered in `routes/console.php` (for example recurring billing generation and invoice reminders). See domain docs 006 and 009.

## Frontend / UX foundation

- Authenticated shell with approved primary navigation.
- Future domains appear in primary navigation as they ship; the dashboard is an operational attention view, not a placeholder.
- Settings groups: Personal, Business (including branding), Automation, AI, System (Audit).
- Light mode is the default appearance.
- Dark mode uses intentional tokens (not a simple invert).
- Restrained neomorphic surfaces via `.neo-surface` / `.neo-inset` CSS utilities.
- Accessibility: focus rings, semantic labels, reduced-motion support, non-colour-only status text.

## Development setup

1. PHP 8.3+, Composer, Node 20+, MySQL, Redis.
2. Copy `.env.example` → `.env` and set MySQL/Redis values.
3. `composer install`
4. `php artisan key:generate`
5. Create MySQL database `adman`.
6. `php artisan migrate --seed`
7. `php artisan adman:create-super-admin`
8. `npm install && npm run build` (or `npm run dev`)
9. `composer run dev` or `php artisan serve`

## Testing

```bash
php artisan test
```

Feature tests use SQLite in-memory (`phpunit.xml`). Foundation coverage is in `tests/Feature/FoundationTest.php`.

## Security notes

- No secrets committed; `.env` is local-only.
- Authorization is server-side (policies/permissions/middleware).
- CSRF protected via Laravel web middleware.
- Mass assignment controlled via explicit `$fillable` / request validation.
- Horizon is not publicly accessible without authorization.
