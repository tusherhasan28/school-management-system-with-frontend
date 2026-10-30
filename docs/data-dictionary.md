# Data dictionary

Covers tables that exist as migrations today (Milestone 1). Planned tables are
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

## Framework tables
`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`
(Laravel defaults; production uses Redis for sessions, cache and queues).
