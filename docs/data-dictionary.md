# Data dictionary

Covers tables that exist as migrations today (Milestones 1–2). Planned tables are
listed in `docs/roadmap.md` and reviewed in `docs/schema-review.md`.

Conventions: InnoDB, `utf8mb4_unicode_ci`, `BIGINT UNSIGNED` ids, timestamps in
UTC. **Tenant table** = has `school_id`, the `BelongsToSchool` trait, and
`UNIQUE (school_id, id)` as the target of composite foreign keys.

## Central (platform) tables

### `schools` — one row per subscribing institution
| Column | Type | Notes |
|---|---|---|
| id | bigint | |
| name / name_bn | varchar(190) | English / Bangla name |
| slug | varchar(63), unique | Subdomain label; validated, reserved list in `config/tenancy.php` |
| institution_type | enum | school, college, kindergarten, madrasa, combined, other |
| status | enum | pending, trial, active, suspended, cancelled, archived. Only `trial`/`active` are reachable |
| email, phone, address, district, division | | Public contact details |
| country_code / timezone / locale / currency | | Defaults BD / Asia/Dhaka / bn / BDT |
| logo_path, primary_color | | Branding (Milestone 6) |
| trial_ends_at, activated_at, suspended_at, suspension_reason | | Lifecycle (`SchoolLifecycle`) |
| deleted_at | | Soft delete only; data is retained |

### `school_domains` — hostname → school
| Column | Type | Notes |
|---|---|---|
| school_id | FK schools | |
| domain | varchar(190), unique | Lower-case host, e.g. `dhaka-model.schoolsaas.test` |
| is_primary | bool | One primary per school, enforced in code |
| verified_at | datetime | |

### `platform_users` — Super Admin staff (guard `platform`)
`name`, `email` (unique), `password` (bcrypt), `role` (owner, administrator,
billing_support, support_agent, auditor), `status` (active, disabled),
`last_login_at`, `remember_token`.

### `platform_activity_logs` — append-only platform audit
`platform_user_id`, `school_id` (nullable), `action`, `subject_type/id`,
`ip_address`, `user_agent`, `metadata` (JSON, redacted), `created_at`.

### `platform_password_reset_tokens`
Standard Laravel token table for platform users.

## Tenant tables

### `users` — people who sign in to a school (guard `web`)
| Column | Notes |
|---|---|
| school_id | FK schools |
| name, email, phone | email and phone unique **per school** |
| password | bcrypt |
| preferred_locale | bn/en |
| status | invited, active, disabled, locked (only `active` can sign in) |
| email_verified_at, phone_verified_at, last_login_at | |
| two_factor_secret / recovery_codes / confirmed_at | Fortify 2FA (encrypted) |
| deleted_at | Soft delete |

### `password_reset_tokens`
Primary key `(school_id, email)` — the same address can exist in two schools.

### `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`
Spatie Permission with teams; the team key is `school_id` (FK schools). Permission
names are global (`App\Support\Permissions\TenantPermissions`); roles are per
school. Default roles: School Owner, School Admin, Principal, Accountant,
Admission Officer, Teacher, Class Teacher, Librarian, Transport Manager,
Receptionist, Student, Parent.

### `activity_logs` — append-only tenant audit
`school_id`, `user_id` (composite FK → `users(school_id, id)`), `action`,
`subject_type/id`, `ip_address`, `user_agent`, `old_values` / `new_values` (JSON,
redacted by `AuditLogger::redact`), `created_at`.

### `school_settings`
`school_id`, `setting_key` (unique per school), `setting_value` JSON.

### `campuses`
`school_id`, `name`, `name_bn`, `code` (unique per school), `address`, `phone`,
`is_main` (one main per school, enforced in `CampusService`).

### `tenant_sequences`
Per-school counters (`admission_no`, `application_no`, `employee_no`; later invoices
and receipts). Row-locked inside the consuming transaction (`TenantSequence`).

## Academic structure (Milestone 2)

### `academic_sessions`
`name` (unique per school), `start_date`, `end_date`, `is_current` (exactly one per
school, `AcademicSessionService::makeCurrent`), `status` (planned/active/completed/archived).

### `classes` (model `SchoolClass`)
`name`, `name_bn`, `code` (both unique per school), `level_order` (promotion order),
`education_stage` (early_years…madrasa/other), `is_active`. Labels are school data;
the setup wizard only *suggests* presets per institution type.

### `sections`
`class_id` (composite FK), `name` (unique per class), `capacity`, `room`, `is_active`.
Extra key `UNIQUE (school_id, class_id, id)` lets enrollments prove a section belongs
to the class.

### `subjects`
`name`, `name_bn`, `code` (unique per school), `subject_type`, `is_active`.

### `class_subjects`
`academic_session_id`, `class_id`, `subject_id` (composite FKs, unique together),
`full_marks`, `pass_marks` DECIMAL(7,2), `is_optional`, `sort_order`.

## People (Milestone 2)

### `staff_members`
Teachers and non-teaching staff (`staff_type`). `employee_no` unique per school,
optional `user_id` (composite FK, unique) for the login, profile and employment fields,
`status` (active/on_leave/inactive), soft deletes.

### `guardians`
`full_name`, `name_bn`, `phone` (normalised to `01XXXXXXXXX`, indexed, not unique),
`alternate_phone`, `email`, `occupation`, `address`, `national_id` (**encrypted**),
optional `user_id` for the future parent portal. Soft deletes.

### `students`
`admission_no` (unique per school, generated `{year}{seq:4}` when blank), names,
`date_of_birth`, `gender`, `blood_group`, `religion`, `nationality`,
`birth_registration_no` (**encrypted**, hidden from JSON, masked for viewers without
`students.update`), addresses, `admission_date`, `status`
(active/inactive/graduated/transferred/withdrawn), optional `user_id`. Soft deletes.

### `student_guardians`
Many-to-many link: `student_id`, `guardian_id` (composite FKs, unique pair),
`relationship`, `is_primary` (one per student, enforced in `GuardianService`), `can_pickup`.

### `student_enrollments`
One row per student per session (`UNIQUE school_id, student_id, academic_session_id`):
`class_id`, `section_id` (FK `(school_id, class_id, section_id) → sections`), `roll_no`
(unique within session/class/section), `status`
(enrolled/promoted/repeated/transferred/withdrawn/graduated), `enrolled_at`,
`ended_at`, `end_reason`. Rows are closed, never deleted, so history is preserved.

## Admissions, imports, invitations (Milestone 2)

### `admission_applications`
`application_no` (`APP-{year}-{seq:5}`), `academic_session_id`, `applied_class_id`,
applicant fields, guardian fields (phone normalised), `application_data` JSON
(father/mother name, religion, blood group), `status`
(submitted/under_review/approved/rejected/enrolled), `reviewed_by`, `reviewed_at`,
`review_note`, `student_id` after conversion, `submitted_at`.

### `data_import_jobs`
`user_id`, `import_type`, `original_name`, `source_path` (inside
`schools/{id}/imports/`), `options` JSON (session/class/section), `status`
(validated/queued/processing/completed/failed), row counters, `preview` JSON (first 25
rows), `error_report_path`, `failure_message`, timestamps.

### `user_invitations`
`user_id`, `token_hash` (sha256 of the emailed token; the token itself is never
stored), `expires_at` (7 days), `accepted_at`, `invited_by`.

### `school_registrations` (central)
Public registration requests: institution and contact details, `requested_slug`,
`status` (pending/approved/rejected), `reviewed_by` (platform user), `reviewed_at`,
`rejection_reason`, `school_id` once approved.

## Academic operations (Milestone 3)

### `teacher_assignments`
`academic_session_id`, `staff_member_id`, `class_id`, `section_id` (null = every
section of the class), `subject_id` (null for class teachers), `assignment_type`
(class_teacher/subject_teacher). Composite FKs to the same school. One class teacher
per section and no duplicate rows (checked in `AssignmentService`). This table drives
`TeachingScope`, which limits what a teacher can see.

### `class_routines`
Timetable periods: `academic_session_id`, `class_id` + `section_id` (FK to
`sections(school_id, class_id, id)`), `subject_id`, optional `staff_member_id`,
`day_of_week` (ISO-8601, 1 = Monday … 7 = Sunday), `starts_at`, `ends_at`, `room`.
Overlaps for the same section, teacher or room on the same day are rejected.

### `holidays`
`name`, `name_bn`, `start_date`, `end_date` (inclusive), `holiday_type`
(public/religious/school/exam_break/other). Weekly off-days are a school setting
(`calendar.weekend_days`, default `[5]` = Friday), not rows.

### `attendance_sessions`
One register per section, date and period: `academic_session_id`, `class_id`,
`section_id`, `attendance_date`, `period_key` (`daily` or `routine:{id}`), optional
`class_routine_id`, `taken_by`, `finalized_at`, `finalized_by`.
`UNIQUE (school_id, section_id, attendance_date, period_key)`.

### `student_attendance`
`attendance_session_id`, `student_id`, `status`
(present/absent/late/leave/half_day), `remarks`.
`UNIQUE (school_id, attendance_session_id, student_id)`. Editable while the session is
a draft; after finalizing, changes go through `attendance_corrections`.

### `attendance_corrections`
Append-only: `student_attendance_id`, `old_status`, `new_status`, `reason`,
`corrected_by`, `created_at`. Each correction is also written to `activity_logs`.
Attendance rate = (present + late + ½ half_day) ÷ (marked − leave).

### `homework`
`academic_session_id`, `class_id`, `section_id` (null = whole class), `subject_id`,
`staff_member_id` (always the signed-in teacher's staff record), `title`,
`instructions`, `attachment_path` (private disk, `schools/{id}/homework/`),
`attachment_name`, `assigned_on`, `due_at`, `max_marks` DECIMAL(7,2), `status`
(draft/published/closed).

### `homework_submissions`
One row per enrolled student when homework is set: `homework_id`, `student_id`
(unique pair), `status` (pending/submitted/late/graded/missing), `submitted_at`,
`submission_text`, `attachment_path` (reserved for the student portal), `marks`
(≤ `max_marks`), `feedback`, `graded_by`, `graded_at`.

## Framework tables
`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`
(Laravel defaults; production uses Redis for sessions, cache and queues).
