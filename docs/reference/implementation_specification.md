# School Management SaaS — Implementation Specification

## 1. Product definition

Build a Bangladesh-first, multi-tenant SaaS platform for schools, colleges, kindergartens, madrasas and combined institutions. Each subscribing institution receives a private administration environment and a public school website hosted at `{school-slug}.platform-domain`.

### Product goals
- One codebase and one configurable academic engine.
- Strict school-to-school data isolation.
- Bangla and English interfaces.
- BDT currency and Asia/Dhaka timezone.
- Responsive browser application; no native mobile app in the initial release.
- Public school website and admission enquiry/application pages.
- Subscription billing and platform-level administration.

### Non-goals for initial MVP
- Native Android/iOS apps.
- Custom-domain support (subdomain only in the approved scope).
- Full ERP/general-ledger accounting.
- AI assistant.
- Complex biometric attendance integrations.
- Separate database per school.

## 2. Technology decisions

- Backend: current supported Laravel release, PHP version required by that release.
- Frontend: React + TypeScript + Inertia.js.
- UI: Tailwind CSS + shadcn/ui + Lucide.
- Database: MySQL 8.0+, InnoDB, utf8mb4.
- Cache/queue: Redis; Laravel Horizon for queue monitoring.
- Auth: Laravel-supported session authentication; Sanctum only where token APIs are needed.
- Permissions: Spatie Laravel Permission or equivalent, configured with school-aware role scoping.
- Tenancy: shared database and shared tables; `school_id` on every tenant-owned row; central platform data separated logically.
- Files: private S3-compatible object storage or private local storage for development.
- Charts: Recharts.
- Testing: Pest or PHPUnit; frontend tests for critical interactions.
- Deployment: Linux VPS, Nginx, PHP-FPM, MySQL, Redis, TLS, scheduled backups.

Pin exact dependency versions in Composer/npm lockfiles. Do not use unmaintained packages.

## 3. Tenancy and routing

### Hostnames
- Marketing: `platform-domain`
- Platform administration: `admin.platform-domain`
- Tenant portal and public website: `{school-slug}.platform-domain`

The tenant subdomain resolves to a school record. Unknown, inactive or suspended tenants must receive safe, non-leaking responses.

### Tenant context
1. Resolve hostname to school.
2. Confirm school status and domain mapping.
3. Set immutable request-scoped tenant context.
4. Authenticate user in the tenant context.
5. Authorize user and resource.
6. Apply tenant scope to all reads and writes.
7. Clear tenant context after request/job completion.

Never trust a client-supplied `school_id`. Derive it from the resolved tenant context. Jobs must carry an explicit tenant identifier and restore tenant context when executing. Cache keys, file paths, exports, search indexes and queued notifications must include tenant identity.

### Isolation safeguards
- Global tenant scopes are a convenience, not the sole security control.
- Services and policies must assert tenant ownership.
- Prevent cross-tenant foreign-key relationships at service layer; use composite unique keys and tenant-aware constraints where feasible.
- Do not expose sequential IDs as authorization.
- Test IDOR and cross-tenant access for every major module.
- Platform support access to tenant data must be explicitly authorized, time-limited, reason-logged and audited.

## 4. Roles

Platform roles:
- Platform Owner
- Platform Administrator
- Billing Support
- Support Agent
- Read-only Auditor

Tenant roles (customizable):
- School Owner
- School Admin
- Principal
- Accountant
- Admission Officer
- Teacher
- Class Teacher
- Librarian
- Transport Manager
- Student
- Parent/Guardian
- Receptionist

Use permissions such as `students.view`, `students.create`, `students.update`, `students.export`, `attendance.mark`, `marks.enter`, `results.publish`, `fees.collect`, `fees.refund`, `users.manage`, `website.manage`. Add resource-level checks for assigned classes, sections, subjects and linked children.

## 5. Functional modules and acceptance criteria

### A. Platform / SaaS
- School registration, verification, approval and activation.
- Plans with configurable limits and feature entitlements.
- Free trial, monthly/yearly subscriptions, renewal, grace period, suspension and cancellation.
- Platform invoices and payment records.
- School usage dashboard.
- Support tickets and platform audit logs.

Acceptance: a platform administrator can create a plan, register a school, assign a subscription, suspend/reactivate it, and see billing history. A school cannot change its plan limits by manipulating frontend requests.

### B. School setup
- Institution profile, logo, contact details, campus, locale, timezone, calendar, academic session.
- Configurable institution type and enabled modules.
- Classes/stages, sections, subjects, grading scale, working days and holidays.

Acceptance: a new school can finish setup without developer assistance; setup state persists and can be resumed.

### C. Admissions and students
- Public admission enquiry/application form.
- Application review, approval/rejection and conversion to student.
- Student profile, guardian relationships, documents, enrollment history.
- Student promotion, transfer, withdrawal and graduation.
- Bulk CSV/XLSX import with validation and downloadable error report.

Acceptance: one guardian can be linked to multiple children; one student can have multiple guardians; enrollment history remains intact across sessions.

### D. Teachers and staff
- Teacher/staff profiles and user accounts.
- Subject/class/section assignments.
- Staff attendance and employment details.
- Role-based access.

Acceptance: teachers only see assigned academic groups and permitted operations.

### E. Academic engine
- Academic sessions, classes, sections, subjects and curriculum mapping.
- Timetable and configurable school calendar.
- Institution type does not fork the codebase.
- Optional semester/term/annual structures.

Acceptance: schools can configure different class labels and academic stages without schema changes.

### F. Attendance
- Daily or period-wise attendance sessions.
- Present, absent, late, leave and half-day statuses.
- Bulk marking, correction with audit trail, summaries and parent notifications.

Acceptance: duplicate attendance for the same student/session is prevented; attendance can be corrected only by authorized roles.

### G. Examinations and results
- Exam types, schedules, subject components, full marks and pass marks.
- Written/MCQ/practical/continuous assessment components.
- Configurable grade bands, GPA rules, optional subjects and pass rules.
- Marks entry, verification, result calculation and publication.
- Printable bilingual report cards.

Acceptance: result calculation is deterministic, versioned and tested against school-approved sample cases. Never hardcode one national grading formula.

### H. Fees and payments
- Fee categories, class/student fee assignment, invoices, discounts, partial payments, allocations, late fees, refunds and receipts.
- Cash/bank/manual collection and online gateway adapter.
- Payment reconciliation and financial reports.

Acceptance: invoice balances are calculated from immutable invoice items and successful allocations; duplicate gateway callbacks are idempotent; no browser redirect can mark a payment successful.

### I. Parent and student portals
- Parent sees only linked children.
- Student sees only own profile and permitted academic records.
- Attendance, timetable, homework, notices, results, invoices and receipts.
- Mobile-first responsive layout.

Acceptance: access tests prove parent A cannot access parent B's child even when changing URLs or IDs.

### J. Teacher portal
- Assigned timetable, classes, attendance, homework, marks entry, notices.
- Class/subject-specific permissions.

### K. Public school website
- Reusable templates, theme settings, pages, menus, notices, gallery/media and SEO fields.
- Public pages render indexable HTML.
- Admission application submission.
- Public data only; never expose private student records.

### L. Notifications
- In-app, email and SMS notification channels.
- Templates in Bangla and English.
- Provider abstraction; retry policy, delivery logs and school-level configuration.
- Queue bulk sends and respect provider rate limits.

### M. Optional post-MVP modules
- Library, transport, hostel, payroll, events, advanced accounting, AI reporting.

## 6. Core workflows

### School onboarding
Register → verify contact → choose trial/plan → create tenant slug → create school defaults → invite owner/admin → setup wizard → import students → launch website.

### Admission conversion
Public form → application record → admission officer review → approve → create student → create/link guardian → create user invitations → create session enrollment.

### Attendance
Teacher opens assigned class → attendance session created → bulk mark → validate roster → save → finalize → queue parent notification → update aggregates.

### Exam results
Configure exam → configure class/subject components → enter marks → validate marks range → verify → calculate using selected grading configuration → principal approval → publish → notify parents/students.

### Fee payment
Create invoice → initiate cash/manual or gateway payment → verify transaction server-side → record successful payment → allocate to invoice → update balance → issue receipt → notify guardian.

## 7. Database conventions

- Use InnoDB and utf8mb4.
- BIGINT UNSIGNED IDs for internal relational entities.
- DECIMAL for money and marks; never FLOAT/DOUBLE for financial values.
- Store timestamps in UTC; convert to Asia/Dhaka for display and school-local date operations.
- Use `school_id` on every tenant-owned table.
- Unique identifiers such as admission number, invoice number and receipt number are unique per school.
- Use soft deletes selectively; financial records should be voided/reversed, not silently deleted.
- Use immutable transaction/audit records.
- JSON only for flexible settings/payloads, not core relational fields.
- Add indexes based on actual query plans.
- Use transactions for enrollment conversion, payment allocation, promotion and result publication.

The companion SQL file is a baseline schema. Convert it into ordered Laravel migrations and review every foreign key, composite tenant constraint and index before production.

## 8. Frontend information architecture

### Marketing site
Home, features, pricing, institution types, demo request, contact, login and school registration.

### Platform admin
Dashboard, schools, plans, subscriptions, invoices, payments, usage, support, platform users, audit and settings.

### Tenant administration
Dashboard, setup wizard, academics, admissions, students, guardians, teachers/staff, attendance, timetable, exams, results, fees, reports, notices, website, integrations and settings.

### Teacher
Dashboard, timetable, assigned classes, attendance, homework, marks and notices.

### Parent
Dashboard, child switcher, attendance, timetable, homework, results, fees, receipts and notices.

### Student
Dashboard, timetable, attendance, homework, results and notices.

Build reusable components: DataTable, filters, forms, modal, confirmation dialog, file uploader, date picker, status badge, pagination, notification center and dashboard cards.

## 9. Non-functional requirements

- Responsive layouts for mobile, tablet and desktop.
- WCAG-informed accessible controls and keyboard navigation.
- Localization files for Bangla and English; no hardcoded UI strings.
- Search, pagination and server-side filtering for large datasets.
- Queue all bulk imports, exports, PDF generation and mass notifications.
- Private document storage with short-lived authorized URLs.
- Rate limiting for login, public forms, SMS and payment endpoints.
- CSRF, secure cookies, output escaping, validation and prepared queries.
- Audit important data changes and privileged access.
- Daily encrypted backups, off-server retention and tested restore procedure.
- Error monitoring, uptime checks and queue failure alerts.
- Data export and retention/deletion workflows.
- No production secrets in source control.

## 10. Testing strategy

Mandatory test suites:
1. Tenant resolution and unknown subdomain handling.
2. Cross-tenant isolation for CRUD, search, exports, files, jobs and APIs.
3. Role and resource authorization.
4. Parent-child relationship authorization.
5. Student admission and promotion.
6. Attendance duplicate prevention and summaries.
7. Grade calculations using approved fixtures.
8. Invoice calculations, partial payments and refunds.
9. Gateway callback signature verification and idempotency.
10. Subscription expiry and grace period.
11. Public website content and SEO output.
12. Bangla rendering and PDF output.
13. Queue retry and failed job handling.

Do not declare a module complete until its feature tests, authorization tests and tenant isolation tests pass.

## 11. Development sequence

### Milestone 0 — Discovery and design
Confirm institution-specific academic workflows, fee rules, grading examples, SMS/payment providers and sample documents. Produce wireframes and data dictionary.

### Milestone 1 — Foundation
Laravel React starter, CI, environments, tenant resolver, school subdomains, auth, permissions, platform admin and audit.

### Milestone 2 — School setup and people
Onboarding, campuses, sessions, classes, sections, subjects, students, guardians, teachers and admissions.

### Milestone 3 — Academic operations
Teacher assignments, timetable, calendar, attendance and homework.

### Milestone 4 — Exams and reports
Exam configuration, marks entry, configurable grading engine, publication and report cards.

### Milestone 5 — Finance
Fee structures, invoices, allocations, manual payments, receipts, gateway adapter and reconciliation.

### Milestone 6 — Portals and public websites
Teacher, parent and student experiences; website templates, content manager, admission forms and SEO.

### Milestone 7 — SaaS billing and notifications
Plans, subscriptions, platform billing, SMS/email, usage limits, support and import/export.

### Milestone 8 — Hardening and launch
Security review, load testing, backups, restore drills, pilot schools, bug fixing and production deployment.

## 12. Definition of done

A feature is complete only when:
- UI is responsive and localized.
- Backend validation and authorization exist.
- Tenant isolation is enforced and tested.
- Loading, empty, error and success states exist.
- Audit trail is added where appropriate.
- Automated tests pass.
- No sensitive data is exposed in logs or public pages.
- Documentation and seed/demo data are updated.
