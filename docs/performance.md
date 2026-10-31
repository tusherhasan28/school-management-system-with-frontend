# Performance: load test and query review (Milestone 8)

## Query review

`tests/Feature/Performance/QueryScalingTest.php` measures the SQL queries behind
the busiest screens with 3 and with 33 students (each with guardian, invoice,
payment and attendance) and fails if a page needs more queries for more rows
(N+1). Current counts (small → large school):

| Screen | Queries |
|---|---|
| Students list | 19 → 14 |
| Guardians list | 7 → 7 |
| Fee invoices | 13 → 13 |
| Payments | 10 → 10 |
| Fees dashboard | 15 → 15 |
| Attendance sheet (one section) | 14 → 14 |
| School dashboard | 18 → 19 |
| Notices (admin) | 8 → 8 |
| Public notices page | 11 → 8 |

Fixes made in this review:

- `whereDate()` on `DATE` columns (`due_date`, `attendance_date`, `start_date`,
  `end_date`, `starts_on`, `ends_on`, `enrolled_at`) wrapped the column in
  `DATE()` and stopped MySQL from using the indexes. All replaced with plain
  comparisons.
- The fees dashboard loaded every open invoice to add up balances in PHP; it now
  sums `ROUND(total*100) - ROUND(paid_total*100)` in SQL (exact paisa).
- UI translations (~120 KB) were sent with every page visit. They are now an
  Inertia "once" prop keyed by language and file version: sent on the first
  visit only. A typical navigation response dropped from ~125 KB to 3–7 KB,
  which matters most on mobile data.

Index review: every tenant table leads its indexes with `school_id`; the portal
and finance lookups (`fee_invoices (school_id, student_id, status, due_date)`,
`fee_payments (school_id, student_id)`, `exam_results (school_id, student_id)`,
`student_attendance (school_id, student_id, status)`, `notices (school_id,
status, publish_at)`) are covered. No new index was needed.

## Load test

Tool: `tools/loadtest/run.mjs` (signs in with Playwright, then sends concurrent
page requests and reports p50/p95). Data: `php artisan ops:load-dataset`
(local/testing only) — one school with **1,500 students, 1,000 guardians,
90,000 attendance marks (60 school days), 4,500 invoices, 750 payments,
60 notices**.

```bash
php artisan ops:load-dataset --students=1500 --days=60
NODE_PATH=$(npm root -g) node tools/loadtest/run.mjs --base=http://load-test.schoolsaas.test:8000 --concurrency=8 --seconds=30
```

Environment of the measured run (sandbox, **not** production hardware):
2 vCPUs shared by PHP, MySQL, Redis and the load generator; PHP 8.3 built-in
server with 8 workers (no php-fpm, no nginx), OPcache on, routes/views/events
cached, `APP_DEBUG=false`.

One user at a time (server time per page):

| Page | p50 | p95 |
|---|---|---|
| Public home / notices | 45 ms | 66 ms |
| Staff dashboard | 111 ms | 139 ms |
| Student list / search | 135–146 ms | 167 ms |
| Fee invoices (overdue) | 144 ms | 198 ms |
| Fees dashboard | 109 ms | 147 ms (was 201/274 before the fixes) |
| Parent overview / attendance / fees | 110–149 ms | 130–172 ms |

Eight concurrent users, 30 s: **562 requests, 18.7 req/s, 0 errors**; p95 under
1 s on every page (CPU-bound: 2 vCPUs ≈ 9 authenticated pages per second each).

## Sizing guidance

- Budget roughly **one vCPU per 8–10 authenticated page views per second** at
  the response times above. A 1,500-student school at a busy moment (results
  day: ~300 parents within 10 minutes ≈ 0.5–1 page/s) fits comfortably on a
  2 vCPU app server; 20–30 such schools on 4 vCPUs plus a separate MySQL server.
- Run php-fpm (pm = static or dynamic, ~2 workers per vCPU), OPcache with
  `validate_timestamps=0` in production, `php artisan optimize`.
- Queue workers (Horizon) on their own processes so imports, exports and bulk
  SMS never compete with page requests during peaks.
- Re-run the load test on staging after large changes and before onboarding a
  big school.
