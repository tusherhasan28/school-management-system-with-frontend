# Data retention and export policy

This policy covers what happens to a school's data when its subscription
changes. It is enforced by the code described in brackets.

## 1. Nothing is deleted automatically

Suspension, cancellation and expiry of a subscription **change the school's
status only** (`SubscriptionService`, `SchoolLifecycle`). Students, guardians,
staff, attendance, results, fees, files and logs stay in the database and in
storage exactly as they were. No scheduled job deletes school data.

| Event | Subscription status | School status | Who can sign in |
|---|---|---|---|
| Trial running | `trialing` | `trial` | Everyone |
| Invoice unpaid after its due date | `past_due` (grace period of the plan, e.g. 10 days) | unchanged | Everyone; admins see a warning banner |
| Grace period over, still unpaid | `suspended` | `suspended` | Nobody (generic "unavailable" page); data kept |
| Payment recorded | `active` | `active` again | Everyone |
| Cancelled now / at period end | `cancelled` | `cancelled` | Nobody; data kept |
| Period ended without renewal | `expired` | `suspended` | Nobody; data kept |

A school that platform staff suspended for another reason (for example abuse)
is **not** reactivated by a payment; only staff can lift that suspension.

## 2. Export

While the school can sign in, its owner/admin (`settings.manage`) can prepare a
full export from **Subscription → Export all school data** (`SchoolDataExporter`):

- one ZIP of UTF-8 CSV files (students, enrolments, guardians, staff,
  attendance, results, invoices, payments, notices) that open in Excel with
  Bangla text intact;
- spreadsheet formulas are neutralised (a leading `=`, `+`, `-`, `@` is
  prefixed with `'`);
- **not included:** national ID and birth registration numbers (they are
  stored encrypted and are not needed to move to another system; the school
  can ask support for them in writing);
- the file is kept for **7 days**, then the download link stops working; only
  admins of that school can download it; requests and downloads are recorded
  in the school's activity log.

Schools are told to export before cancelling. A school that is already
suspended or cancelled can ask the platform team, who reactivate it
temporarily (status `active`, audited) so the owner can sign in and export.

## 3. How long data is kept after a school leaves

- **Cancelled, expired or suspended schools:** kept for **180 days** from the
  date the school stopped being accessible, so a returning school continues
  where it left off.
- After 180 days the platform owner may **archive** the school (status
  `archived`). Permanent deletion of an archived school's data is a manual,
  two-person platform operation, recorded in the platform audit log, and is
  not automated in this release (see Milestone 8 hardening).
- **Platform invoices and payments** are financial records of the platform and
  are kept for at least **6 years** after the financial year (they are never
  deleted by the application; unpaid invoices are voided, not removed).
- **Audit logs** (school and platform) are kept for the life of the school
  record.
- **Message delivery logs** hold the text and recipient needed to retry a
  message. They are kept with the school's data; masked recipients are shown
  on screens.

## 4. Platform access to school data

Platform staff do not browse school records. A support agent can ask for
access with a reason and a duration (1–24 hours, `BILLING_SUPPORT_ACCESS_MAX_HOURS`);
the school's admin approves or rejects it and can revoke it at any time. An
approved grant shows a read-only snapshot (users, recent activity, failed
messages, usage counts) and every view is written to both the platform and the
school audit logs. Plan usage figures shown to platform staff are counts only.
