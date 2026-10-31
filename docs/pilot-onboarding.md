# Pilot school onboarding

A runbook for bringing the first schools onto production. Plan 2–3 weeks per
school: one week of setup and data, one week running in parallel with the
old process, then switch.

## Before the first meeting

- Agree the plan and price (Platform → Billing → Plans) and who pays.
- Collect: school name (English and Bangla), EIIN, address, phone, logo,
  subdomain (e.g. `rajuk-uttara`), owner/principal name and email.
- Ask for their current student list (Excel), class/section structure, subject
  list per class, fee chart, grading rules and a sample old report card.
- Sign a data processing agreement: the school owns its data; we process it,
  back it up, and export or delete it on request (`docs/retention-policy.md`).

## Day 1 — create the school

```bash
php artisan school:create rajuk-uttara --name="..." --owner-name="..." --owner-email="..." --status=trial
php artisan school:readiness rajuk-uttara      # lists what is still missing
```

`school:create` asks for the owner's first password: share it by phone, not
email. Assign the plan (Platform → Schools → subscription). The owner signs in
at `https://<slug>.APP_DOMAIN`, changes the password and **turns on two-factor
login** (Settings → Two-factor).

## Week 1 — setup with the school office

Work through the School setup page (`/app/setup`) with the office, in this order:

1. School profile (Bangla name, address, district), logo.
2. Academic session → make it current; classes, sections, subjects, class subjects.
3. Grading scale: compare our default with their old report card; adjust and
   approve it with the exam controller **before** entering marks.
4. Staff: add teachers and office staff, give each the right role, send
   invitations (teachers need an email for now).
5. Students: Import → download the template → fill from their Excel →
   upload → fix the rows the validator rejects → import. Check counts per
   section against the school's register.
6. Teacher assignments (subject per section) and class routine.
7. Fees: categories, fee structures for the session, discounts/scholarships;
   generate the first month's invoices and compare totals with their fee chart.
8. Messaging: SMS provider account (when an adapter for their provider is
   available) or email/in-app only; check the message templates in Bangla.
9. Website: profile text, principal's message, notices; publish.
10. Parents: invite guardians that have an email; others are added as the
    portal gets phone logins.

Run `php artisan school:readiness <slug>` again: no **FAIL** lines before going live;
review each **WARN** with the school.

## Week 2 — parallel run

- Daily attendance in the app (teachers on phones) and on paper.
- Fees collected at the counter are entered as payments; compare the day's
  collection report with the cash book every evening.
- One class test entered and a draft report card printed and checked by the
  exam controller.
- Collect feedback in a shared list; fix blockers before switch-over.

## Go-live and after

- Switch: paper attendance and the old fee book stop; the app is the record.
- First month: weekly call with the principal and accountant; watch
  `ops:health`, failed jobs and support tickets daily.
- Support: the school opens tickets from Help & Support; platform staff never
  open school data without a time-limited access grant approved by the school.
- After 1 month: review usage (Platform → School), agree on the paid plan,
  convert the trial.

## Pilot success criteria

- ≥ 90 % of school days with attendance taken in the app.
- Fee collections in the app match the school's cash book every week.
- One exam's results published and report cards printed from the app.
- No data-isolation or data-loss incident; restore drill done on production.
