# Security, authorization and tenant isolation review (Milestone 8)

Reviewed 31 October 2026 against the master prompt's security rules and the
spec's non-functional requirements. Everything below marked ✅ is enforced by an
automated test unless stated otherwise.

## 1. Tenant isolation

| Control | Status / evidence |
|---|---|
| Every table classified as central or tenant; tenant tables have `school_id`, an FK to `schools`, and a `(school_id, id)` unique key used by composite tenant FKs | ✅ `IsolationAuditTest` (fails when a new table is added unclassified) |
| Every model on a tenant table uses `BelongsToSchool` (fail-closed global scope) and `school_id` is never mass-assignable | ✅ `IsolationAuditTest` |
| Every tenant route is authenticated and behind `staff` or `portal:*` middleware; every platform route behind `auth:platform` + `platform.host` | ✅ `IsolationAuditTest` route audit |
| **Cross-tenant sweep**: school A's owner, teacher, parent and student call every id-taking tenant route (137 calls, all HTTP verbs) with school B's ids → all 403/404, and school B's data is unchanged afterwards | ✅ `CrossTenantSweepTest` |
| Foreign ids smuggled in form fields and array keys (section, student, guardian, fee, exam ids) are rejected | ✅ `BodyIdIsolationTest` |
| `school_id` never taken from form, query or header (resolved from the host) | ✅ (code review + sweep) |
| Sessions bound to the school; host-only cookies (`SESSION_DOMAIN=null`) | ✅ earlier milestones' tests |
| Files: per-school storage roots, ownership checked before serving | ✅ sweep uses distinct per-school files (a shared path would have hidden a leak) |
| Cache keys and queued jobs carry the school (`TenantCache`, `QueueTenancy`) | ✅ M1 tests |
| Platform staff access to school data only through school-approved, time-limited, audited support grants | ✅ `SupportTest` |

**Result: no cross-tenant read or write found.**

## 2. Authorization

| Finding | Severity | Status |
|---|---|---|
| Attendance take/store/report validated the request before checking the role, so a user without attendance rights got 422 (with field names) instead of 403 | Low (no data exposed) | ✅ Fixed: role checked first; `AuthorizationSweepTest` |
| Support ticket reply/decide validated before loading the school's record | Low | ✅ Fixed: record looked up first |
| User with no roles calls every parameter-less `/app` route | — | ✅ 403 everywhere except the dashboard and calendar (`AuthorizationSweepTest`) |
| Portal users (parent/student) reach only their own children / themselves | — | ✅ `PortalAccessTest` + sweep |
| Platform abilities (`billing.*`, `support.*`, `schools.*`, `audit.view`, `horizon.view`) | — | ✅ M7 tests |

## 3. Web security

| Control | Status |
|---|---|
| CSRF on every state-changing web route (gateway callbacks excepted; they verify signatures) | ✅ framework + route review |
| Content-Security-Policy with per-request nonce (no `unsafe-inline` scripts), `frame-ancestors 'self'`, `object-src 'none'`, `base-uri 'self'`; X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy; HSTS and COOP on HTTPS | ✅ `SecurityHeadersTest` (every executable inline script carries the nonce) |
| `appearance` cookie (unencrypted, rendered in the page) could inject markup | ✅ Fixed: whitelisted to light/dark/system |
| Output escaping: React escapes by default; the only raw-HTML paths are Markdown rendered with `html_input=escape` and unsafe links removed, JSON-LD with `JSON_HEX_TAG`, and plain-text mail | ✅ code review |
| Sensitive fields never flashed back to the session on validation errors (passwords, NID, birth registration no., credentials, API keys, secrets, tokens) | ✅ `dontFlash` |
| Uploads: size and type limits on every upload (imports 5 MB csv/xlsx, attachments 10 MB, images with dimension limits), stored outside `public/` | ✅ code review |
| Rate limits: login, 2FA, platform login, password change, invitations, public forms (admission, registration), payment start/callback/return, imports, invoice generation, payments, support access, `/ops/health` | ✅ route review |
| Payment status never set from a browser redirect; only verified callbacks/reconciliation | ✅ M5 tests |
| Session: Redis, encrypted, host-only, `secure` in production (deployment checklist) | ✅ config |

## 4. Secrets and personal data

| Control | Status |
|---|---|
| No credentials, domains or API keys in source; `.env.example` placeholders only | ✅ repository grep |
| Gateway and SMS credentials stored with Laravel's `encrypted:array` cast and hidden from serialization | ✅ |
| NID / birth-registration numbers hidden from model serialization; not logged | ✅ |
| Demo seeder refuses outside local/testing; `ops:load-dataset` likewise | ✅ |
| Backups encrypted (AES-256, PBKDF2) with integrity checksums; passphrase and `.env` kept out of backups | ✅ restore drill (`docs/operations.md`) |
| Health endpoint: token-protected (constant-time compare), returns counts only, 404 when not configured | ✅ `OpsHealthTest` |

## 5. Dependencies

- `npm audit --omit=dev`: **0 vulnerabilities** (31 Oct 2026).
- `composer audit`: **no advisories found**, but in this sandbox the package
  metadata came from a local cache (Packagist mirror unreachable). Re-run
  `composer audit` on a normal network before go-live and on every release.

## 6. Open items (not blocking the pilot)

- Two-factor login is available but not **enforced** for school staff
  (`school:readiness` warns), and the **platform admin login has no two-factor
  option yet**. Add platform 2FA before more staff get platform accounts.
- No external penetration test yet; recommended before scaling past the pilot.
- No error tracker is bundled (logs only); add Sentry/Flare or log shipping.
- Passkeys stay disabled (relying-party id would need to be per school host).
- Report on CSP violations is browser-console only (no `report-uri` collector).
