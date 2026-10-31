# Architecture — School Management SaaS (Bangladesh)

Status: Milestone 1 (foundation and tenancy). This document records decisions and
the reasoning behind them. Update it whenever a decision changes.

## 1. Shape of the system

- **Modular monolith** on Laravel 13 + Inertia 3 + React 19 + TypeScript.
- **Shared MySQL 8 database, shared tables.** Every tenant-owned row carries
  `school_id`. Central (platform) tables carry no tenant scope.
- **Redis** for cache, queues and sessions in production; **Horizon** monitors queues.
- Tests run against a real MySQL database (`school_saas_test`) because composite
  foreign keys and InnoDB behaviour are part of the security model and SQLite would
  not exercise them.

### Module layout

```
app/
  Support/Tenancy/        TenantContext, scopes, traits, queue + cache helpers
  Http/Middleware/        ResolveTenant, EnsureUserBelongsToTenant, ...
  Models/                 Central models (School, SchoolDomain, PlatformUser)
  Modules/<Module>/       Tenant domain modules (Models, Http, Policies, Services, ...)
    Setup/                Campuses, setup wizard, school settings
    Academics/            Sessions, classes, sections, subjects, class-subject mapping
    People/               Students, guardians, enrollments, staff; promotion lifecycle
    Admissions/           Public applications, review, conversion
    Imports/              CSV/XLSX import (reader, validation, queued job)
    Teaching/             Teacher assignments, TeachingScope/TeachingAccess, timetable, calendar
    Attendance/           Attendance sessions, marks, corrections, absence notification job
    Homework/             Homework, attachments, submissions and grading
    Finance/              Fee setup, invoices, payments, receipts, reports, gateway layer
      Gateways/           PaymentGateway interface, registry, payload sanitizer
    Exams/                Exam setup, grading scales, marks entry, results, report cards
      Grading/            Pure grading engine (no DB): Hundredths, GradingConfig, GradingRules, GradingEngine
  Support/Authorization/  TenantPolicy base (permission + same-school check)
  Services/Audit/         AuditLogger (tenant) and PlatformAuditLogger
```

Each module owns its models, form requests, policies, services/actions, events,
jobs and tests. Controllers stay thin and call services.

## 2. Hostnames and routing

| Host | Purpose | Guard |
|---|---|---|
| `APP_DOMAIN` (e.g. `schoolsaas.test`) | Marketing site, pricing, school registration | none |
| `admin.APP_DOMAIN` | Platform (Super Admin) dashboard | `platform` |
| `{slug}.APP_DOMAIN` | One school: public website, admin panel, portals | `web` (tenant users) |

`APP_DOMAIN` comes from `.env`; nothing is hard-coded. Routes are registered in
this order (`bootstrap/app.php`):

1. `routes/marketing.php` — `Route::domain(APP_DOMAIN)`, middleware `web`.
2. `routes/platform.php` — **path-only** (`/platform/*`), middleware
   `platform.host` → `web`. `EnsurePlatformHost` 404s any host other than
   `admin.APP_DOMAIN`. Path-only routes keep Wayfinder's generated frontend
   URLs relative (a domain-bound route would bake the domain into the JS bundle).
3. `routes/tenant.php` — no domain constraint, middleware `tenant` → `web` →
   `tenant.session`. Registered last, it matches any other host; `ResolveTenant`
   404s hosts that are not a known school domain. Fortify's routes are required
   inside this group, so login, password reset and 2FA exist only on school hosts.

School identity is taken from the resolved hostname, **never** from request input.
Middleware priority is configured so `ResolveTenant` runs before the session
starts and `EnsureSessionBelongsToTenant` runs before authentication, Inertia
shared props and route-model binding.

Reserved subdomains (`admin`, `www`, `api`, `mail`, `static`, ...) cannot be used
as school slugs (`config/tenancy.php`).

### Tenant path namespace (avoids public/private collisions)

| Path | Audience |
|---|---|
| `/`, `/about`, `/academics`, `/teachers`, `/notices`, `/events`, `/gallery`, `/contact`, `/p/{slug}`, `/media/*`, `/sitemap.xml`, `/robots.txt`, `/admission` | Public website (no auth, Blade) |
| `/home` | Sends a signed-in user to their area |
| `/login`, `/logout`, `/forgot-password`, ... | Tenant authentication |
| `/app/*` (e.g. `/app/dashboard`, `/app/students`, `/app/notices`) | School administration |
| `/teacher/*`, `/student/*`, `/parent/*` | Portals |
| `/settings/*` | Personal account settings (starter kit) |

The spec lists admin paths such as `/notices` and public `/notices`; putting all
administration under `/app` is the deliberate namespace that keeps both.

## 3. Tenant context

`App\Support\Tenancy\TenantContext` is a request-scoped (`scoped`) singleton.

1. `ResolveTenant` middleware normalises the host, looks up `school_domains.domain`,
   loads the school and checks `schools.status`.
2. Unknown host → 404 with a generic page. Suspended/cancelled/archived/pending →
   a generic "unavailable" response (HTTP 403/503) that does not reveal whether
   the slug exists beyond what the public DNS already shows.
3. The request runs inside `TenantContext::run($school, ...)`. Within it the
   context is immutable: `set()` with a different school throws. `run()` always
   restores the previous state in `finally`, so nothing leaks to the next request
   on a long-lived worker.
4. Spatie Permission's team id follows the context (set/cleared with it).
5. The app locale is set from the school (`bn` default).

### Data access safeguards (defence in depth)

1. **Global scope** `TenantScope` on every tenant model (`BelongsToSchool` trait).
   It **fails closed**: with no tenant context it adds `WHERE 1 = 0`. Platform code
   that truly needs cross-tenant reads must call `withoutTenancy()` explicitly.
2. **Model guard**: on `creating` the trait fills `school_id` from the context; on
   `saving` it throws `CrossTenantWriteException` if the row's `school_id` differs
   from the context. `school_id` is never mass assignable.
3. **Composite foreign keys**: every tenant table has `UNIQUE (school_id, id)` and
   child tables reference parents with `(school_id, parent_id) → parent(school_id, id)`.
   The database itself rejects a row in school A pointing at a record in school B.
   Implemented via the `tenantForeign()` Blueprint macro.
4. **Route model binding** resolves through the scoped query, so a guessed ID from
   another tenant returns 404.
5. **Policies** check the permission *and* `school_id` ownership.
6. **Form requests** validate referenced IDs with tenant-scoped `exists` rules
   (`TenantRule::exists('students')`).
7. **Session isolation**: session cookies are host-only (`SESSION_DOMAIN=null`), so a
   session from school A is never sent to school B. In addition
   `EnsureUserBelongsToTenant` logs out any authenticated user whose `school_id`
   does not match the resolved tenant.

### Queues, cache, files

- **Jobs**: `Queue::createPayloadUsing` adds `school_id` to every payload dispatched
  while a tenant context is active. On `JobProcessing` the worker restores the
  context; on `JobProcessed`/`JobExceptionOccurred` it puts the previous state back
  (works for the sync driver inside a request too). Tenant jobs add the
  `EnsureTenantIsAccessible` job middleware so work for a suspended school is
  dropped (data is kept). Note: `Job::dispatch()` sends when its PendingDispatch
  is destroyed — dispatch as a statement inside the tenant scope, not as the
  return value of a closure passed to `run()`.
- **Cache**: `TenantCache` prefixes keys with `school:{id}:`. Do not call `Cache::`
  directly with tenant data.
- **Files**: `TenantStorage::path('documents/x.pdf')` → `schools/{id}/documents/x.pdf`
  on a private disk. Downloads go through controllers that authorize and stream, or
  short-lived signed URLs bound to the tenant host.

### Payments and money
- Every change to what is owed or paid happens in one transaction with the affected
  invoice rows locked; `InvoiceLedger` recomputes the cached totals from invoice lines
  and live allocations (successful, unreversed payments) every time.
- Manual payments carry a browser-generated idempotency key (unique per school), so a
  resubmitted form returns the first payment.
- Online payments (no adapter installed yet): an adapter implements
  `App\Modules\Finance\Gateways\PaymentGateway` following the provider's current
  official documentation and is listed in `config/finance.php` only after sandbox
  tests pass. Its `parseCallback()` must verify the provider signature. Settlement
  happens only in `OnlinePaymentService::apply()`, from a verified callback or a
  server-to-server `query()`; it is idempotent (row lock, final states never change,
  unique gateway transaction id) and refuses amounts that differ. The browser return
  URL only displays status and queues reconciliation. `finance:run reconcile` runs
  every 15 minutes; `finance:run late-fees` daily at 01:30 Dhaka time (cron must run
  `php artisan schedule:run` every minute).

## 4. Authentication and authorization

- **Tenant users** (`users` table, `web` guard) — authenticated through Laravel
  Fortify on the tenant host only. Email is unique per school, not globally, so the
  login query is scoped to the current school. Password-reset tokens are keyed by
  `(school_id, email)` (`TenantPasswordTokenRepository`). The login throttle key
  includes the host. Self-registration and self-deletion are disabled: accounts are
  managed by the school. Passkeys are disabled until the relying-party id can be
  set per school host.
- **Platform users** (`platform_users` table, `platform` guard) — separate model,
  separate login page on `admin.` host, no Fortify registration.
- **Permissions**: Spatie Laravel Permission with *teams* enabled; team key is
  `school_id`. Roles are per school (School Owner, Principal, Teacher, ...). Platform
  roles use the `platform` guard with a null team.
- **Resource-level access for teachers** (Milestone 3): role permissions say *what*
  a teacher may do (`attendance.mark`, `homework.create`); `TeachingScope` says
  *where*, using the teacher's assignments in the current session. Lists are
  constrained in the query (`TeachingScope::constrain`), single records are checked
  in policies (`StudentPolicy::view`) and in `TeachingAccess`
  (`canTakeDaily` = class teacher of that section, `canTakePeriod` = teacher of that
  routine). Records outside the scope return 403 or 404, never a filtered page that
  reveals they exist. Leadership roles bypass the scope through `attendance.view_all`
  / `homework.view_all`. Teacher roles no longer carry `students.view`.
- **Family portals** (Milestone 6): a parent login is linked to one `guardians`
  row, a student login to one `students` row (`user_id`). `PortalAccess` resolves
  the student from the signed-in user: a parent's `{student}` must be one of the
  guardian's linked children, otherwise 404 (not 403, so other ids reveal nothing);
  the student portal takes no student id at all. Every further id in a portal URL
  (exam, invoice, payment, homework) is looked up *through* that student. The
  service caches nothing, so unlinking a guardian takes effect on the next request.
  `portal:parent|student|teacher` middleware gates each area; `staff` middleware
  redirects parent/student-only logins away from `/app`. A teacher who is also a
  parent has one login with both roles.
- **Support access to tenant data** (Milestone 7): a platform user with
  `support.manage` requests access with a reason and 1–24 hours
  (`support_access_grants`). The school's admin approves (the clock starts then),
  rejects or revokes it. Only that agent, only while approved and unexpired, can
  open a read-only snapshot (users and roles, recent activity, failed messages,
  usage counts); every view increments a counter and is written to both the
  platform and the school audit logs. There is no impersonation.

## 4a. Billing (Milestone 7)

- Central tables `plans`, `plan_features`, `subscriptions`, `platform_invoices`,
  `platform_payments` belong to the platform and reference `schools.id` with plain
  foreign keys (no tenant scope). School screens read them with the school id of
  the resolved host only, and have no write endpoints.
- `PlanFeatures` is the catalogue of keys the code enforces; values are rows in
  `plan_features`. `Entitlements` reads the current subscription's plan for the
  tenant and throws a validation error before an action would pass a limit.
  `UsageMeter` counts active students, staff, SMS parts this month and storage.
- `SubscriptionService::tick()` (daily `billing:run`) issues renewal invoices,
  rolls periods, starts grace (`past_due`) and suspends; payments and voids call
  `settle()`. School status moves through `SchoolLifecycle`, so the existing
  access rules apply; a school suspended by staff for another reason is not
  reactivated by billing (suspension reasons from billing start with
  "subscription").

## 4b. Messaging (Milestone 7)

- `MessageService::send()` renders the template per recipient language
  (`MessageTemplates`: built-in Bangla/English text, school overrides in
  `notification_templates`), writes one `notification_logs` row per recipient and
  channel, and dispatches `DeliverMessage` on the `messages` queue after commit.
- `DeliverMessage` restores the tenant (QueueTenancy), skips suspended schools,
  is rate-limited per school at the provider's per-minute rate
  (`RateLimiter::for('messaging-sms')`), retries with backoff, marks permanent
  failures at once and never sends a message whose log is already `sent`.
- SMS adapters implement `SmsProvider` and are listed in `config/messaging.php`;
  none for a real gateway ship yet. School credentials live in
  `sms_provider_accounts.credentials` (encrypted, hidden, write-only in the UI).
- In-app notifications use Laravel's `notifications` table and are only read
  through the signed-in user; notification links must be same-site paths.

### Admin-host-only tools
Horizon runs on `admin.APP_DOMAIN` only (`config/horizon.php` domain + `platform.host`
middleware + `auth:platform`) and is limited to platform owners/administrators in
every environment. Guest redirects choose the login page by host, not path.

## 4c. Operations and hardening (Milestone 8)
- **Security headers** (`SecurityHeaders` middleware, `config/security.php`): CSP with a per-request Vite nonce for every inline script; HSTS/COOP only on HTTPS; skipped while Vite runs hot.
- **Health** (`App\Support\Ops\HealthChecks`): shared by `ops:health --alert` (scheduled every 5 min, emails `OPS_ALERT_EMAIL`, once per cooldown per check, plus recovery) and `GET admin.APP_DOMAIN/ops/health` (token, no session, 503 on failure). The scheduler writes a heartbeat every minute so a dead scheduler is caught from outside.
- **Backups** (`scripts/backup.sh`, `scripts/restore.sh`): mysqldump + storage archive, encrypted with a passphrase file, `SHA256SUMS`, and `manifest.json` (row count + `CHECKSUM TABLE` per table) checked by `ops:verify-restore` after every restore.
- **Isolation as tests**: new tables, models or routes fail `IsolationAuditTest` until classified; new id-taking routes are swept automatically by `CrossTenantSweepTest` (add a fixture to `CreatesFullTenantDataset`).
- **Performance guards**: `QueryScalingTest` fails on N+1; translations are an Inertia `once` prop keyed by language and file version.

## 5. Audit and logging

- `activity_logs` (tenant) and `platform_activity_logs` (central).
- `AuditLogger` redacts keys listed in `config/tenancy.php` (`password`, `token`,
  `national_id`, `birth_registration_no`, `nid`, gateway secrets, ...). Values are
  never written to application logs either.

## 6. Money, marks, time

- `DECIMAL(12,2)` for money, `DECIMAL(7,2)` for marks. No floats. The grading engine
  works in integer hundredths (`Hundredths`), so results are identical on every
  server; percentages are rounded down to 0.01 before banding, and GPA rounding is a
  per-scale rule.
- Results are reproducible: every calculation stores the grading configuration and
  exam setup it used, the engine version and a hash of the marks
  (`ResultService::reproduces()` re-runs a stored version and compares).
- Database stores UTC (`APP_TIMEZONE=UTC`); display and school-local date logic use
  the school's timezone (default `Asia/Dhaka`).

## 7. Localisation

- UI strings live in `lang/en.json` and `lang/bn.json`; the React side reads them via a
  shared Inertia prop and a `useTranslation()` hook.
- Bangla font: Hind Siliguri / Noto Sans Bengali, loaded with the UI font stack.

## 8. Decisions taken with safe defaults

| Decision | Default chosen | Where to change |
|---|---|---|
| Admin URL namespace on tenant host | `/app/*` | `routes/tenant.php` |
| School status allowed to log in | `trial`, `active` (and `past_due` during grace — M7) | `config/tenancy.php` |
| Test database | MySQL `school_saas_test` | `phpunit.xml` |
| Test framework | PHPUnit 12 (Pest optional later) | `composer.json` |
| Unknown tenant response | 404 generic page | `ResolveTenant` |
| Inaccessible school response | 503 generic page (`errors/school-unavailable`) | `ResolveTenant` |
| Platform admin language | English | `TENANCY_PLATFORM_LOCALE` |
| Platform roles | Fixed enum (`PlatformRole`), not Spatie | `app/Enums/PlatformRole.php` |
| Schools without a subscription | Not limited, listed as "unmanaged" on the platform | `Entitlements` |
| Missing plan feature row | Fail-closed (switch off, limit 0) | `PlanFeatures::CATALOGUE` |
| Renewal invoice lead time | 7 days | `BILLING_INVOICE_LEAD_DAYS` |
| Payment instructions on invoices | From env, not code | `BILLING_PAYMENT_INSTRUCTIONS` |
| SMS in development | "Log" provider (nothing sent) only in local/testing | `MESSAGING_ALLOW_LOG_SMS` |
| Automatic message channels | Email until the school adds SMS | `config/messaging.php` defaults, Messages → Settings |
| Report card output | Browser print / "Save as PDF" (correct Bangla shaping, no server PDF dependency) | `pages/app/exams/report-cards.tsx` |
| Advance payments / credit | Not supported (payment ≤ amount owed) | `PaymentService::plan()` |
| Late fees | Off until a school enables them | Fees → Setup |
| Grading defaults | GPA 5 preset with half-up GPA rounding, 4th-subject bonus above 2.00 — editable per scale | `GradingPresets`, scale editor |
| Public website rendering | Server-rendered Blade (indexable, works without JavaScript; no Node SSR process in production) instead of Inertia SSR | `resources/views/site` |
| Website text format | Markdown, raw HTML escaped, unsafe links dropped | `Website\Services\Markdown` |
| Uploaded website images | Re-encoded to JPEG with GD (EXIF/GPS removed), max 1600 px, 480 px thumbnail | `WebsiteMedia` |
| Teachers on the public site | Nobody until the school ticks them; name and designation only | Website → Teachers |
| Portal logins | Email invitation only (SMS OTP in M7) | `PortalAccounts` |
| Brand accent | Deep teal `oklch(0.46 0.085 185)` | `resources/css/app.css` |

## 9. Trade-offs

- Shared tables are cheaper to operate and migrate than database-per-tenant, but a
  single missing scope can leak data. That is why there are four independent layers
  (scope, model guard, composite FKs, policies) plus tests that try IDOR on purpose.
- Composite FKs add an index per parent table (`school_id, id`) and make migrations
  slightly more verbose; the safety is worth it.
- Fail-closed scope means platform code must be explicit. That is intentional friction.
- The public site is Blade while the app is React. Two template systems to maintain,
  but crawlers get complete HTML, phones on slow connections get small pages without
  a JavaScript bundle, and production needs no Node SSR server.
