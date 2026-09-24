# ADMAN

Business administration and automation platform.

## Quick start

See [technical_Docs/001-application-foundation.md](technical_Docs/001-application-foundation.md) for architecture and setup details.

```bash
cp .env.example .env
# configure MySQL + Redis in .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan adman:create-super-admin
npm install && npm run build
composer run dev
# operator checks (optional locally): php artisan adman:health
```

Production deployment, backups, and runbooks: [technical_Docs/011-production-readiness.md](technical_Docs/011-production-readiness.md).

## Product foundation

Approved product documents live in `foundational_docs/`.

Domain docs:

- [Application foundation](technical_Docs/001-application-foundation.md)
- [Contacts domain](technical_Docs/002-contacts-domain.md)
- [Communication domain](technical_Docs/003-communication-domain.md)
- [Quotes, invoices, documents](technical_Docs/004-quotes-invoices-documents.md)
- [Payments](technical_Docs/005-payments-domain.md)
- [Recurring billing](technical_Docs/006-recurring-billing-domain.md)
- [Email delivery](technical_Docs/007-email-delivery.md)
- [WhatsApp communication](technical_Docs/008-whatsapp-communication.md)
- [Invoice reminders](technical_Docs/009-invoice-reminders.md)
- [AI + human handoff](technical_Docs/010-ai-human-handoff.md)
- [Production readiness](technical_Docs/011-production-readiness.md)
- [Staging deployment & UAT](technical_Docs/012-staging-deployment-and-uat.md)
