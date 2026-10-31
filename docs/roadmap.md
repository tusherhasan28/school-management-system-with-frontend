# Roadmap and implementation checklist

Status legend: ✅ done and tested · 🟡 partly done · ⬜ not started

## Milestone 0 — Inspect and plan ✅
- ✅ Repository and runtime inspected (empty repo; PHP 8.3, Node 22, MySQL 8.0, Redis 7)
- ✅ `docs/architecture.md`, `docs/data-dictionary.md`, `docs/schema-review.md`, this roadmap
- ✅ Reference spec, schema and prompt stored in `docs/reference/`

**Open questions for the product owner (not blocking):**
1. Production domain name (replaces `schoolsaas.test` in `.env`).
2. Approved grading examples (GPA tables, optional-subject rules) per institution type. Milestone 4 shipped with a configurable engine and hand-calculated illustrative fixtures (`tests/Unit/Exams/GradingEngineTest.php`); real marksheets from a pilot school should be added there as approved fixtures before go-live.
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

## Milestone 2 — School setup and people ✅
- ✅ School registration on the marketing site (honeypot, rate limit, live web-address check) → platform review → approve (provisions a trial school, invites the contact as School Owner) / reject with reason
- ✅ Resumable setup wizard: profile, current session, classes + sections (presets per institution type, fully editable), subjects
- ✅ Academic sessions (exactly one current), classes, sections, subjects, class-subject mapping with full/pass marks per session; delete guards
- ✅ Students with encrypted birth registration number, generated admission numbers, enrollment per session, roll numbers unique per section
- ✅ Guardians many-to-many with students; siblings share one guardian (same name + normalised phone); primary guardian; last link protected
- ✅ Teachers/staff profiles; optional login with a staff role via emailed, single-use, expiring invitation (hash stored)
- ✅ Public admission form → review (under review / approve / reject with reason) → transactional, idempotent conversion into student + guardian + enrollment
- ✅ Promotion / repeat in bulk with history preserved; transfer / withdraw / graduate close the enrollment
- ✅ CSV and XLSX student import: validation, duplicate detection (file + database), preview, queued import (tenant-aware job), per-row transactions, downloadable error report (formula-injection safe)
- ✅ Dashboard: active students, staff, applications to review, students-by-class chart, setup progress
- ✅ Horizon restricted to the admin host and platform owners/admins
- ✅ 148 automated tests (51 new for this milestone)

## Milestone 3 — Academic operations ✅
- ✅ Teacher assignments per session: class teacher (one per section) and subject teacher (whole class or one section; subject must be mapped to the class); only active teaching staff
- ✅ Resource-level access via `TeachingScope`: teachers see students, attendance and homework only for their current-session assignments; disabled staff lose access; `attendance.view_all` / `homework.view_all` for leadership
- ✅ Class timetable (ISO weekdays) with section, teacher and room clash checks; teacher's own weekly timetable; class derived from the section, never the form
- ✅ School calendar: configurable weekly off-days (default Friday), holidays (single or multi-day, typed), working-day count per year
- ✅ Attendance: daily (class teacher) and per-period (subject teacher of that routine) sessions; one session per section/date/period at DB level; future dates, off-days and holidays rejected; only enrolled students on that date; draft → finalize; changes after finalizing are append-only corrections with reason and an audit entry
- ✅ Optional queued email to the primary guardian on absence (tenant-aware job; off by default)
- ✅ Monthly attendance report per section and per-student attendance summary on the profile; today's progress on the dashboard
- ✅ Homework with private attachment (`schools/{id}/homework/…`), per-student submission rows, grading with max-marks check, setter taken from the signed-in user
- ✅ Bangla translations for every new screen; dates follow the active language and school timezone
- ✅ 178 automated tests (30 new for this milestone)

## Milestone 4 — Examinations ✅
- ✅ Exam types; exams per session with dates and a grading scale; class subjects added in bulk with a marks-distribution template (written, written + MCQ, written + MCQ + practical, written + continuous assessment), then adjusted per subject: parts, full marks, part pass marks, subject pass marks, compulsory / optional (4th) / non-GPA, schedule
- ✅ Configurable grading scales: grade bands (validated to cover 0–100 without gaps, one fail band) and rules — GPA method (average / optional-subject bonus / none), bonus threshold, cap, decimals, rounding, subject-mark rounding, fail rules, absence rule, merit order, ranking of failed students. Presets are starting points only; nothing national is hardcoded
- ✅ Deterministic grading engine in integer hundredths (`App\Modules\Exams\Grading`), engine version stored with each result
- ✅ Workflow: draft → marks entry (setup frozen, one mark sheet per subject × section) → submit → verify (or return with reason) → calculate → approve → publish → reopen with reason
- ✅ Teachers enter marks only for their assigned subject/section (class teachers for their whole section); `marks.enter_all` for exam controllers; every change to saved marks is audited with old and new totals
- ✅ Versioned results: each calculation is an immutable `result_calculations` row with the grading and setup snapshot, a config hash and a hash of every mark; earlier versions stay on record; approval/publication blocked if the scale changed after calculation; publishing locks the scale
- ✅ Results tabulation per class/section with positions, grade distribution and pass rate; class teachers see their own section
- ✅ Bilingual (English + Bangla on every label) printable A4 report cards, single or whole class/section, with attendance, merit position, grading key and signature lines; drafts carry a watermark. Printed through the browser, which shapes Bangla correctly ("Save as PDF" gives a PDF)
- ✅ Optional email of a result summary to each primary guardian on publication (queued, tenant-aware)
- ✅ Exam results on the student profile (drafts only for `results.view`); new role **Exam Controller**
- ✅ English labels for all status keys (`lang/en.json` was empty, so English screens showed raw keys such as `class_teacher`); filter dropdowns on several M3 pages no longer stretch full width
- ✅ 212 automated tests (34 new for this milestone: 22 engine fixtures, 12 workflow)

## Milestone 5 — Finance ✅
- ✅ Fee heads (bilingual) and per-session fee plans: class-wide or opt-in (e.g. transport), monthly / quarterly / half-yearly / yearly / one-time from a chosen first month
- ✅ Per-student overrides (0 waives a class fee) and discounts/scholarships (percent or fixed, one fee head or all)
- ✅ Invoice generation per month (all classes or one), idempotent: a database key allows one live invoice per student per month, so re-runs and double clicks skip existing ones; one-off invoices for single charges
- ✅ Append-only invoice lines: discounts recorded on the line; waivers and extra charges are new lines; late fees (fixed or % of balance, after a grace period, once per invoice) added by a nightly scheduled job; cancellation only without live payments, kept on record
- ✅ Payments: cash, bank, cheque, mobile banking (reference required); allocated oldest-due-first or to chosen invoices inside one transaction with the student's invoices locked; no over-collection; double submission returns the first payment (browser-generated key + unique index)
- ✅ Immutable records: successful payments, allocations, invoice lines and reversals refuse updates/deletes; invoices are never deleted. Reversal (mistake, bounced cheque) or refund (money returned) is a separate row, needs `fees.refund`, and restores the invoice balance
- ✅ Invoice totals/paid amounts are caches recomputed from lines and live allocations in every transaction (`InvoiceLedger`); tests assert they match
- ✅ Bilingual printable A5 receipts and invoices (browser print; PAID / CANCELLED / REVERSED watermarks)
- ✅ Online payment architecture without any made-up provider: `PaymentGateway` interface, empty registry, encrypted per-school credentials, signature-verified CSRF-exempt callback endpoint, idempotent settlement (unique gateway transaction id, repeated callbacks harmless, amount mismatch held for review), return page that never marks anything paid, reconciliation job (every 15 minutes) that queries the provider and expires stale attempts. Exercised end-to-end in tests with a test-only adapter
- ✅ Fees dashboard (today/month collected, outstanding, overdue, student search), invoice and payment lists with filters, student fee account, reports (collections by day/method/collector net of reversals; dues by class with aging) and XLSX exports (formula-injection safe)
- ✅ New role grants: Accountant manages and collects but cannot refund; Principal views, refunds and sees reports
- ✅ 244 automated tests (32 new for this milestone)

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

## Known limitations after Milestone 5
- No online gateway adapter is installed: bKash, Nagad, Rocket and SSLCommerz need merchant credentials and current official documentation (open question 4). Mobile-banking payments are recorded manually with their transaction ID.
- No advance/credit balance: a payment cannot exceed what is owed. An online payment that arrives after the invoice was settled in cash stays partly unallocated and is flagged for a manual refund.
- Reversal/refund is for a whole payment; partial refunds are not supported. Refunding an online payment through the gateway is manual.
- Discounts and plan changes apply to invoices generated afterwards; issued invoices are corrected with waiver/charge lines.
- No expense ledger or accounting exports (chart of accounts, journal) yet.
- Fee reminders by SMS/email arrive with the notification channels in Milestone 7; parents see invoices and pay online in the portal (Milestone 6).
- Exams: grading fixtures are illustrative, not yet approved by a pilot school (see open question 2).
- Report cards are printed from the browser; there is no server-generated PDF file or bulk ZIP download yet.
- One optional (4th) subject counts per student; a student with several optional papers has the best one counted and the rest reported as non-GPA. There is no per-student subject choice record yet; "not taking" is entered per mark sheet (exempt).
- No combined/annual result across several exams (e.g. 30% half-yearly + 70% annual) yet.
- Students and guardians cannot view results online until the portals in Milestone 6; publication emails a summary only.
- Students cannot submit homework themselves yet; teachers record submission status. Student/parent portals arrive in Milestone 6.
- Absence alerts are email only. SMS needs the provider abstraction in Milestone 7.
- Attendance is per day or per routine period; there is no hourly/biometric import.
- Timetable has no substitute-teacher workflow or printable PDF yet.
- Imports cover students (with one guardian per row). Staff and guardian-only imports are not built yet.
- Student photos and document uploads are not built yet (storage helpers exist).
- No custom roles UI yet; schools use the default roles (`tenancy:sync-permissions` keeps them current).
- Passkeys disabled (relying-party id must be per school host).
- Starter-kit settings pages (profile, password, 2FA, appearance) are still English-only.
- Bangla validation messages cover the common rules only; others fall back to English.
- Marketing, pricing and registration pages are placeholders.
- No plans/subscriptions yet: school status is changed manually by platform admins.
- Wayfinder output (`resources/js/actions`, `routes`) is generated, not committed — run `php artisan wayfinder:generate --with-form` or `npm run build`.
- `composer.lock` was resolved from GitHub sources in an offline-from-Packagist sandbox. On a normal machine run `composer update` once to refresh dist URLs; versions stay within the same constraints.

## Test coverage (Milestone 5 additions)
| Area | File |
|---|---|
| Paisa arithmetic, percent rounding, billing months per frequency | `tests/Unit/Finance/MoneyTest.php` |
| Generation with class/opt-in fees, overrides, discounts; re-runs; one class; session bounds; cancel and regenerate; waivers/charges; one-off invoice; late fees (grace, once, percent, off); permissions | `tests/Feature/Finance/BillingTest.php` |
| Oldest-first and chosen allocation, over-collection, validation, double submission, reversal/refund, cache = ledger, cancellation guard, immutability, roles, cross-school isolation | `tests/Feature/Finance/PaymentTest.php` |
| No gateway by default, verified callback settles once, bad signature / wrong amount / failure, one transaction cannot pay twice, browser return never pays, reconciliation and expiry, cash-meanwhile excess, encrypted credentials | `tests/Feature/Finance/GatewayTest.php` |
| Dashboard numbers, lists, student account, invoice/receipt/print, reports and XLSX export, access per role | `tests/Feature/Finance/FinanceScreensTest.php` |

## Test coverage (Milestone 4 additions)
| Area | File |
|---|---|
| Engine fixtures: optional-subject bonus, cap, rounding modes, component pass marks, optional fail rule, absence, exempt, best optional, percentage boundaries, subject-mark rounding, merit ties, percentage-only scale, average method, fail rule off, order independence, snapshot reproduction, band validation | `tests/Unit/Exams/GradingEngineTest.php` |
| Full workflow with expected GPA/grade/positions, frozen setup, mark validation + audit, teacher scope, versioned recalculation + immutability, stale scale, scale lock/duplicate, approval/publication permissions, class-teacher result scope, profile visibility, guardian email, cross-school isolation | `tests/Feature/Exams/ExamWorkflowTest.php` |

## Test coverage (Milestone 3 additions)
| Area | File |
|---|---|
| Daily/period sessions, duplicates, finalize, corrections + audit, future/off-day/holiday rules, roster by enrolment date, absent-guardian job, cross-school | `tests/Feature/Attendance/AttendanceTest.php` |
| Class vs subject teacher, other sections 403, students limited to assignments, last session ignored, disabled staff, section order | `tests/Feature/Teaching/TeacherAccessTest.php` |
| Assignment rules, timetable clashes, own timetable, calendar settings/holidays permissions, cross-school IDs | `tests/Feature/Teaching/TimetableAndAssignmentTest.php` |
| Homework scope, private attachment + download isolation, roster rows, grading limits, setter from user, list scoping | `tests/Feature/Homework/HomeworkTest.php` |

## Test coverage (Milestone 2 additions)
| Area | File |
|---|---|
| Student admission, guardians many-to-many, sequences, encryption + masking, section/class FK, roll uniqueness, cross-school references and IDOR, filters, roles, promotion/repeat/graduation history | `tests/Feature/People/StudentManagementTest.php` |
| Staff logins, invitations (single use, expiry, re-invite, other school), role limits | `tests/Feature/People/StaffAndInvitationTest.php` |
| Public form (no data leak, honeypot, foreign class, rate limit), review transitions, conversion (sibling guardian reuse, idempotent), cross-school | `tests/Feature/Admissions/AdmissionFlowTest.php` |
| CSV/XLSX preview counts, duplicates, confirm, sections per row, error report + formula injection, cross-school files, permissions | `tests/Feature/Imports/StudentImportTest.php` |
| Registration, slug rules, approval provisioning + owner invite, rejection, platform roles | `tests/Feature/Platform/SchoolRegistrationTest.php` |
| Setup wizard, presets, current session, delete guards, class subjects, per-school uniqueness, teacher access, Horizon lockdown | `tests/Feature/Academics/AcademicsAndSetupTest.php` |

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
