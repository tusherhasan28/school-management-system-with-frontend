# Review of `school_saas_schema.sql` (baseline)

The supplied SQL is a useful map of the domain, but it is not production-ready as is.
It is kept at `docs/reference/school_saas_schema.sql` for reference only; the real
schema lives in ordered Laravel migrations. Findings below are applied as each
module's migrations are written.

## Tenant isolation

| # | Issue | Fix in migrations |
|---|---|---|
| 1 | Child tables use single-column FKs (`student_id → students.id`), so a row in school A can reference a student in school B. | Every tenant table gets `UNIQUE (school_id, id)`; children use composite FKs `(school_id, x_id) → parent(school_id, id)` via the `tenantForeign()` macro. |
| 2 | Several unique keys omit `school_id` (`student_guardians (student_id, guardian_id)`, `student_enrollments (student_id, academic_session_id)`, `exam_subjects`, `student_marks`, `exam_results`, `assignment_submissions`, `grading_scale_items`). Correct in practice once FKs are composite, but they should lead with `school_id` for index use. | Unique keys lead with `school_id`. |
| 3 | `support_tickets.created_by_user_id`, `support_ticket_messages.sender_id`, `salary_structures.employee_id`, `payroll_items.employee_id` have no FK (polymorphic). | Split into explicit nullable FK columns (`teacher_id`, `employee_id`) or a single `staff` table; sender stored as `platform_user_id` / `user_id` pair. |
| 4 | `website_menus.parent_id` and `page_id` can point to another school's menu/page. | Composite FKs. |
| 5 | `roles` with `school_id NOT NULL` conflicts with Spatie's schema and with platform roles. | Use Spatie tables with teams enabled (`school_id` nullable team key); platform roles use guard `platform`. |
| 6 | `notification_logs`, `activity_logs` reference `users.id` without tenant pairing. | Composite FK on `(school_id, user_id)`. |

## Correctness / integrity

| # | Issue | Fix |
|---|---|---|
| 7 | `school_domains.is_primary` defaults to TRUE, so a school can have several primaries. | Default FALSE; enforce one primary per school in the service (MySQL has no partial unique index). |
| 8 | `fee_invoices.paid_total` and `status` are denormalised but nothing guarantees they match allocations. | Keep as cached columns, recomputed inside the allocation transaction from `fee_payment_allocations`; tests assert consistency. |
| 9 | No refund/reversal tables; `fee_payments.status = 'reversed'` would mutate an immutable record. | Add `fee_payment_reversals` (and refund entries) as new rows; original payment never edited after `successful`. |
| 10 | `fee_payments` unique `(gateway, transaction_id)` is global — fine for idempotency, but `transaction_id` NULL for cash means MySQL allows many NULLs (intended). Document it. | Keep, document. |
| 11 | `exam_subjects` hard-codes written/MCQ/practical columns, but the spec asks for configurable components. | `exam_subject_components` (name, full_marks, pass_marks, weight) and `student_mark_components`. |
| 12 | Results store no calculation version, so a published result cannot be reproduced after a grading-scale edit. | `exam_results.calculation_snapshot JSON` + `grading_scale_version`; grading scales become versioned/immutable once used. |
| 13 | `grading_scale_items` has no guard against overlapping ranges. | Service-level validation + tests. |
| 14 | `attendance_sessions` unique key excludes `academic_session_id` — fine (date is unique), but a section can move sessions; keep, add `CHECK`-style validation in service. | Keep. |
| 15 | `library_issues` allows both or neither of `student_id`/`teacher_id`. | MySQL 8 `CHECK` constraint + validation. |
| 16 | `classes.uq_class_name (school_id, name)` blocks the same class name on different campuses. | Add `campus_id` to the key if multi-campus classes are needed (decide in M2). |
| 17 | `students.religion`, `blood_group`, birth registration: sensitive. | Encrypted casts for identifiers; never logged; excluded from exports unless permission `students.export_sensitive`. |
| 18 | `guardians.phone NOT NULL` without uniqueness — duplicate guardian rows will appear on import. | Duplicate detection on `(school_id, phone)` in the import service; not a hard unique (siblings' parents may share a phone). |

## Missing tables

Framework: `sessions`, `password_reset_tokens`, `cache`, `jobs`, `job_batches`,
`failed_jobs` (from Laravel). Domain: `holidays`, `academic_calendar_events`,
`working_days`, `exam_schedules`, `fee_payment_reversals`, `refunds`,
`notification_templates`, `notification_preferences`, `support_access_grants`,
`expense_approvals`, `outbox_events`, `hostel_*`.

## Conventions confirmed

InnoDB, `utf8mb4_unicode_ci`, `BIGINT UNSIGNED` ids, DECIMAL money/marks, UTC
timestamps, soft deletes only on people records (`users`, `students`, `teachers`,
`guardians`) and never on financial rows.
