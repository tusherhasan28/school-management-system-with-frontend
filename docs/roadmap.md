# Roadmap and implementation checklist

Status legend: ✅ done and tested · 🟡 partly done · ⬜ not started

## Milestone 0 — Inspect and plan ✅
- ✅ Repository and runtime inspected (empty repo; PHP 8.3, Node 22, MySQL 8.0, Redis 7)
- ✅ `docs/architecture.md`, `docs/data-dictionary.md`, `docs/schema-review.md`, this roadmap
- ✅ Reference spec, schema and prompt stored in `docs/reference/`

**Open questions for the product owner (not blocking):**
1. Production domain name (replaces `schoolsaas.test` in `.env`).
2. Approved grading examples (GPA tables, optional-subject rules) per institution type — needed for Milestone 4 fixtures.
3. Fee rules from pilot schools: late-fee formula, discount types, billing periods.
4. SMS provider (e.g. SSL Wireless, BulkSMSBD) and payment gateway merchant accounts with sandbox credentials.
5. Whether one class name may repeat across campuses (`schema-review.md` #16).

## Milestone 1 — Foundation and tenancy ✅
- ✅ Laravel 13 React starter kit (Inertia 3, React 19, TS, Tailwind 4, shadcn/ui)
- ✅ MySQL 8 + Redis config; tests run on MySQL
- ✅ Hostname tenant resolver, unknown/inactive tenant handling, reserved slugs
- ✅ Request/job-scoped immutable `TenantContext`; fail-closed `TenantScope`; `BelongsToSchool` write guard
- ✅ Composite tenant foreign keys (`tenantId`, `tenantKeys`, `tenantForeign` Blueprint macros)
- ✅ Route namespaces: marketing / platform / tenant (`/app`, auth, public)
- ✅ Tenant auth via Fortify (scoped login, per-school reset tokens, host-aware throttle, 2FA)
- ✅ Platform auth (separate guard, role enum, audit of logins)
- ✅ Spatie Permission with school teams + default roles
- ✅ Session bound to school; cross-school session reset
- ✅ Queue tenancy (payload, restore, clear, suspended-school job middleware)
- ✅ `TenantCache`, `TenantStorage`, `TenantRule`
- ✅ Tenant and platform audit logs with redaction
- ✅ Reference module: Campuses (model, request, policy, service, controller, page)
- ✅ Platform: dashboard, schools list/detail, suspend/reactivate
- ✅ `php artisan school:create`, `php artisan platform:create-admin`, local demo seeder
- ✅ Bangla/English UI (shared translations, Hind Siliguri font), responsive layouts
- ✅ 97 automated tests (see "Test coverage" below)

## Milestone 2 — School setup and people ⬜
School registration + approval flow (marketing → platform), setup wizard (resumable),
school settings, academic sessions, classes/stages, sections, subjects,
class-subject mapping, students, guardians (many-to-many), teachers/staff, user
invitations, admission applications and conversion (transactional), CSV/XLSX import
with preview, duplicate detection and error report (queued).

## Milestone 3 — Academic operations ⬜
Teacher assignments (resource-level access), timetable, calendar/holidays/working
days, attendance sessions (daily/period, duplicates rejected, corrections audited),
homework + submissions.

## Milestone 4 — Examinations ⬜
Exam types and schedules, configurable components, marks entry/verification,
versioned grading scales, deterministic calculation with stored snapshot,
approval/publication, bilingual report cards (PDF with Bangla shaping).

## Milestone 5 — Finance ⬜
Fee categories/structures/assignments, invoices + items, discounts, late fees,
manual payments, allocations in transactions, immutable payments + reversal rows,
receipts, reports, gateway adapter interface (bKash/Nagad/Rocket/SSLCommerz added
only with real sandbox credentials), idempotent callbacks, reconciliation job.

## Milestone 6 — Portals and public websites ⬜
Teacher, parent (child switcher, linked children only), student portals; website
templates, pages/menus editor, notices, gallery, admission form, SEO (server-rendered
meta, sitemap, canonical URLs). Inertia SSR for public pages.

## Milestone 7 — SaaS billing and notifications ⬜
Plans + plan features/limits (database-configured), subscriptions (trial, renewal,
past-due, grace, suspension, cancellation), platform invoices/payments, usage
dashboard, support tickets, time-limited audited support access, email/SMS
provider abstraction with Bangla/English templates and delivery logs.

## Milestone 8 — Hardening ⬜
Isolation review, authorization review, load test, query tuning, backup/restore
drill, pilot onboarding, production deployment guide.

## Known limitations after Milestone 1
- Passkeys disabled (relying-party id must be per school host).
- Starter-kit settings pages (profile, password, 2FA, appearance) are still English-only.
- Bangla validation messages cover the common rules only; others fall back to English.
- Marketing, pricing and registration pages are placeholders.
- No plans/subscriptions yet: school status is changed manually by platform admins.
- Wayfinder output (`resources/js/actions`, `routes`) is generated, not committed — run `php artisan wayfinder:generate --with-form` or `npm run build`.
- `composer.lock` was resolved from GitHub sources in an offline-from-Packagist sandbox. On a normal machine run `composer update` once to refresh dist URLs; versions stay within the same constraints.

## Test coverage (Milestone 1)
| Area | File |
|---|---|
| Unknown/central hosts, case-insensitive hosts, inaccessible statuses, reserved slugs | `tests/Feature/Tenancy/TenantResolutionTest.php` |
| Listing isolation, ID guessing (read/update/delete), school_id in query/header/body, scoped unique rules, cross-school sessions, same email in two schools, shared-prop leaks | `tests/Feature/Tenancy/CrossTenantAccessTest.php` |
| Fail-closed scope, write guards, immutability, composite FKs at DB level, per-school roles | `tests/Feature/Tenancy/TenantModelGuardTest.php` |
| Queue payload/restore/clear, sync jobs, suspended-school jobs, cache keys, storage paths, password reset tokens, audit redaction, suspension | `tests/Feature/Tenancy/TenantInfrastructureTest.php` |
| Platform auth, guard separation, role abilities, suspend/reactivate | `tests/Feature/Platform/PlatformAccessTest.php` |
| Campus CRUD, role access, validation, disabled users, search | `tests/Feature/Setup/CampusManagementTest.php` |
| Starter kit auth/settings adapted to school hosts | `tests/Feature/Auth`, `tests/Feature/Settings` |
