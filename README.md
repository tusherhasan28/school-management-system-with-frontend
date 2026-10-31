# School SaaS — multi-tenant school management for Bangladesh

One platform for schools, colleges, kindergartens and madrasas. Each institution
gets its own subdomain (`{school}.your-domain`) with an admin panel, portals and a
public website, all in Bangla and English, with strict data isolation between
schools.

**Status:** Milestones 1 (foundation and tenancy), 2 (school setup and people),
3 (academic operations: teacher assignments, timetable, calendar, attendance, homework)
4 (examinations: configurable grading, marks entry, versioned results, bilingual
report cards), 5 (fees: invoices, payments, receipts, reversals, reports, online
payment architecture), 6 (public school websites, parent/student portals, teacher
dashboard, notices) and 7 (SaaS plans and limits, subscriptions and platform
billing, support tickets and audited support access, SMS/email messaging,
data export) complete. See
[`docs/roadmap.md`](docs/roadmap.md) for what is built and what comes next.

## Stack
Laravel 13 · Inertia 3 · React 19 + TypeScript · Tailwind CSS 4 + shadcn/ui ·
MySQL 8 · Redis + Horizon · Spatie Permission (teams) · Fortify · Recharts · PHPUnit.

## Documentation
- [Architecture and tenancy design](docs/architecture.md)
- [Data retention and export policy](docs/retention-policy.md)
- [Data dictionary](docs/data-dictionary.md)
- [Review of the baseline SQL schema](docs/schema-review.md)
- [Roadmap, checklist and known limitations](docs/roadmap.md)
- **[Install on cPanel shared hosting + Cloudflare](docs/install-cpanel.md)** · [Production deployment guide (VPS)](docs/deployment.md) · [Operations: backups, restore, monitoring](docs/operations.md)
- [Pilot school onboarding](docs/pilot-onboarding.md) · [Security and isolation review](docs/security-review.md) · [Performance and load test](docs/performance.md)

## Local setup

Requirements: PHP 8.3+, Composer, Node 22+, MySQL 8, Redis.

```bash
composer install
cp .env.example .env            # set DB_PASSWORD; keep APP_DOMAIN=schoolsaas.test for local
php artisan key:generate
mysql -e "CREATE DATABASE school_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
          CREATE DATABASE school_saas_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate --seed       # demo data, local only
npm install && npm run build
```

### Hostnames
Schools are resolved by hostname, so `localhost` alone will not work. Either use
Laravel Herd/Valet (`*.test` resolves automatically) or add hosts entries:

```
127.0.0.1 schoolsaas.test admin.schoolsaas.test dhaka-model.schoolsaas.test ctg-madrasa.schoolsaas.test
```

then `php artisan serve` and open, for example, `http://dhaka-model.schoolsaas.test:8000`.

### Demo accounts (local seed only; password `password`)
| Where | Email |
|---|---|
| `admin.schoolsaas.test/platform/login` | `owner@schoolsaas.test` |
| `admin.schoolsaas.test/platform/login` (billing) | `billing-support@schoolsaas.test` |
| `admin.schoolsaas.test/platform/login` (support) | `support-agent@schoolsaas.test` |
| `dhaka-model.schoolsaas.test/login` | `principal@dhaka-model.test` |
| `dhaka-model.schoolsaas.test/login` (class teacher of 6-A, Math for Class 6) | `salma@dhaka-model.test` |
| `dhaka-model.schoolsaas.test/login` (Accountant) | `accounts@dhaka-model.test` |
| `dhaka-model.schoolsaas.test/login` (parent of Ayesha and Nusrat) | `parent@dhaka-model.test` |
| `dhaka-model.schoolsaas.test/login` (student Tanvir) | `student@dhaka-model.test` |
| `ctg-madrasa.schoolsaas.test/login` | `principal@ctg-madrasa.test` |

Dhaka Model School is seeded with a finished setup (session 2026, classes 1–10 with
sections A/B, subjects), four students (two siblings sharing a guardian), a teacher
with class-teacher and subject assignments, a Sunday–Thursday timetable, two holidays,
one homework, a published Half-Yearly exam for Class 6 (results and report cards), a
draft Annual exam, fee plans with September–November invoices and payments (one family
overdue with late fees), a published bilingual website (pages, notices for each
audience, events, a generated photo album), parent and student portal logins, three demo plans (Dhaka Model on Standard, monthly, first invoice paid;
Chattogram Alia Madrasa on a Starter trial), a support ticket with a reply,
SMS in test mode with one sent message, and one admission application. A pending school registration waits in the platform admin.
The public website is at `dhaka-model.schoolsaas.test` (add `?lang=en` for English) and the admission form at `/admission`.

### Useful commands
```bash
php artisan school:create my-school --name="My School" --owner-name="..." --owner-email="..."
php artisan platform:create-admin --name="..." --email="..."
php artisan tenancy:sync-permissions   # after adding permissions/roles in TenantPermissions
php artisan test                 # runs against the school_saas_test MySQL database
npm run types:check
php artisan horizon              # queue workers (Redis): imports, emails, SMS and exports need a worker (queues: default, messages)
php artisan school:readiness my-school   # go-live checklist for a school
php artisan ops:preflight        # installation check (PHP, .env, database, cache, queue, mail)
php artisan ops:health           # database, cache, queues, failed jobs, Horizon, scheduler, backups, disk
php artisan schedule:work        # local scheduler: late fees and billing:run (daily), payment reconciliation (15 min)
```

## Production notes
- Point a wildcard DNS record `*.your-domain` and the root/`admin` hosts at the app,
  with a wildcard TLS certificate.
- Keep `SESSION_DOMAIN=null` (host-only cookies) and set `SESSION_SECURE_COOKIE=true`.
- Never commit `.env`; payment, SMS and mail credentials go in environment variables
  or per-school encrypted integration settings (Milestone 5/7).
- The seeder refuses to run outside `local`/`testing`.
- Shared hosting: `scripts/build-release.sh` makes an upload-ready zip (vendor +
  built assets); install with [docs/install-cpanel.md](docs/install-cpanel.md),
  update with `scripts/cpanel-update.sh`.
- Full VPS guide: [docs/deployment.md](docs/deployment.md) (nginx, php-fpm, Horizon,
  cron, `scripts/deploy.sh`, encrypted backups with `scripts/backup.sh`, go-live checklist).
