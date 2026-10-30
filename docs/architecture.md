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
    Setup/                Campuses (reference implementation for every module)
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

`APP_DOMAIN` comes from `.env`; nothing is hard-coded. Route files:

- `routes/marketing.php` — `Route::domain(APP_DOMAIN)`
- `routes/platform.php` — `Route::domain('admin.'.APP_DOMAIN)`, prefix `/platform`
- `routes/tenant.php` — `Route::domain('{school}.'.APP_DOMAIN)` + `tenant` middleware

The `{school}` route parameter is only used to match; `ResolveTenant` forgets it
after resolution so it never reaches controllers. School identity is taken from the
resolved hostname, **never** from request input.

Reserved subdomains (`admin`, `www`, `api`, `mail`, `static`, ...) cannot be used
as school slugs (`config/tenancy.php`).

### Tenant path namespace (avoids public/private collisions)

| Path | Audience |
|---|---|
| `/`, `/about`, `/admission`, `/notices`, `/contact`, `/p/{slug}` | Public website (no auth) |
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
3. `TenantContext::set($school)` — can be set once; setting a different school in
   the same lifecycle throws. `TenantContext::run($school, fn)` is the only way to
   switch temporarily (jobs, seeders, commands) and always restores the previous
   state in `finally`.
4. Spatie Permission team id is set to the school id.
5. The context is cleared when the request/job finishes.

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
  context; on `JobProcessed`/`JobFailed`/`JobExceptionOccurred` it clears it. A job
  for a suspended school is released/failed rather than run.
- **Cache**: `TenantCache` prefixes keys with `school:{id}:`. Do not call `Cache::`
  directly with tenant data.
- **Files**: `TenantStorage::path('documents/x.pdf')` → `schools/{id}/documents/x.pdf`
  on a private disk. Downloads go through controllers that authorize and stream, or
  short-lived signed URLs bound to the tenant host.

## 4. Authentication and authorization

- **Tenant users** (`users` table, `web` guard) — authenticated through Laravel
  Fortify on the tenant host only. Email is unique per school, not globally, so the
  login query is scoped to the current school.
- **Platform users** (`platform_users` table, `platform` guard) — separate model,
  separate login page on `admin.` host, no Fortify registration.
- **Permissions**: Spatie Laravel Permission with *teams* enabled; team key is
  `school_id`. Roles are per school (School Owner, Principal, Teacher, ...). Platform
  roles use the `platform` guard with a null team.
- **Support access to tenant data** (Milestone 7): a `support_access_grants` row with
  reason, expiry and approver; every access is audited.

## 5. Audit and logging

- `activity_logs` (tenant) and `platform_activity_logs` (central).
- `AuditLogger` redacts keys listed in `config/tenancy.php` (`password`, `token`,
  `national_id`, `birth_registration_no`, `nid`, gateway secrets, ...). Values are
  never written to application logs either.

## 6. Money, marks, time

- `DECIMAL(12,2)` for money, `DECIMAL(7,2)` for marks. No floats; PHP side uses
  `brick/math` (already a Laravel dependency) for arithmetic.
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

## 9. Trade-offs

- Shared tables are cheaper to operate and migrate than database-per-tenant, but a
  single missing scope can leak data. That is why there are four independent layers
  (scope, model guard, composite FKs, policies) plus tests that try IDOR on purpose.
- Composite FKs add an index per parent table (`school_id, id`) and make migrations
  slightly more verbose; the safety is worth it.
- Fail-closed scope means platform code must be explicit. That is intentional friction.
