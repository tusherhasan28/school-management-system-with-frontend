# Operations: backups, restore and monitoring

## Backups

`scripts/backup.sh` runs daily from cron (see `docs/deployment.md`). Each run:

1. writes `manifest.json`: row count and `CHECKSUM TABLE` of every table;
2. dumps the database with `mysqldump --single-transaction` (consistent, no
   locking), gzip, then **AES-256 encryption** (`openssl enc -aes-256-cbc
   -pbkdf2 -iter 200000`, passphrase read from a file, never on the command line);
3. archives and encrypts uploaded files (`storage/app`: logos, documents, media,
   imports, exports);
4. writes `SHA256SUMS`, so tampering or a damaged copy is detected before restore;
5. copies the backup off the server with rclone (`BACKUP_RCLONE_REMOTE`), checks
   the copy, and applies retention (local 14 days, remote 35 days by default);
6. only then updates `storage/app/ops/last-backup`, which the `backup` health
   check reads (alert if older than 26 hours).

Settings: `deploy/backup.env.example` → `/etc/school-saas/backup.env`.

Not in the backup on purpose: `.env` (secrets) and the backup passphrase. Keep
both in the team password manager; a backup cannot be used without them.

## Restore

Always restore into a **new** database first, verify, then switch:

```bash
# 1. Pick a backup (local or fetched back with `rclone copy remote:path/STAMP ./STAMP`)
ls /var/backups/school-saas/

# 2. Restore + verify (refuses the live database and non-empty databases)
RESTORE_MYSQL_DEFAULTS=/etc/school-saas/admin.my.cnf \
  scripts/restore.sh /var/backups/school-saas/20261031T020000Z school_saas_restored /tmp/restored-storage

# 3. Maintenance mode, point the app at the restored copy, restore files
php artisan down
#    set DB_DATABASE=school_saas_restored in .env (or rename databases)
rsync -a --delete /tmp/restored-storage/app/ storage/app/
php artisan optimize && php artisan horizon:terminate
php artisan up
```

`ops:verify-restore` compares every table's row count and checksum with the
manifest and exits non-zero on any difference. The manifest is taken a moment
before the dump, so on a busy server a few fast-moving tables can differ;
allow those explicitly, e.g.
`RESTORE_VERIFY_IGNORE="--ignore=sessions --ignore=cache --ignore=cache_locks --ignore=jobs --ignore=activity_logs"`.
The app user needs `SELECT` on the restored database for the verification.

Restoring **one school** only (e.g. a school deleted data by mistake) is not a
button: restore the whole backup into a side database and copy that school's
rows back with care (every tenant table has `school_id`). Do this with a
second person checking, after a fresh backup of the live database.

## Restore drill — 31 October 2026 (sandbox)

| Step | Result |
|---|---|
| Data | 3 schools incl. the 1,500-student load-test school: 87 tables, 113,968 rows (90,000 attendance marks), 24 uploaded files |
| Backup (`scripts/backup.sh`) | 2.1 s, 784 KB encrypted (DB 720 KB, files 58 KB); files start with `Salted__` (encrypted), no plain text |
| Wrong passphrase | restore stops at decryption, nothing imported (0 tables) |
| One byte appended to a backup file | `SHA256SUMS` check fails, restore refused |
| Restore over the live database | refused |
| Restore into `school_saas_restore_drill` | 7 s; `ops:verify-restore`: **all 87 tables, 113,968 rows, identical checksums** |
| Uploaded files | `diff -r` against the original: identical |
| App on the restored copy | public site 200; principal logs in; students page lists students, Bangla UI |

MySQL 8.0.46, OpenSSL 3.0.13. Repeat the drill on the production server
(restore last night's off-server copy into a side database) **before the first
pilot school goes live, then every 3 months**, and record the result here.

## Monitoring and alerts

| What | How |
|---|---|
| Uptime | External monitor (UptimeRobot, Better Stack, …) on `https://APP_DOMAIN/up` and on a school site, every 1–5 min |
| Deep health | Same monitor on `https://admin.APP_DOMAIN/ops/health?token=OPS_HEALTH_TOKEN` — 503 when any check fails; also catches a dead scheduler |
| Scheduled checks | `ops:health --alert` every 5 minutes emails `OPS_ALERT_EMAIL` (once per hour per failing check, plus a recovery mail) and logs at `critical` |
| Queue waits | Horizon "long wait" mails (`config/horizon.php` → `waits`) to `OPS_ALERT_EMAIL` |
| Errors | Log channel `daily` at `warning`+; ship logs or add an error tracker (Sentry/Flare) via `LOG_STACK` — none is bundled |

Checks in `ops:health`:

| Check | Fails when | First response |
|---|---|---|
| database | cannot query | `systemctl status mysql`, disk, credentials |
| cache | Redis read/write fails | `systemctl status redis` |
| queues | `default` > 500 or `messages` > 5,000 waiting | Horizon dashboard; is Horizon running? scale `maxProcesses` |
| failed_jobs | any job failed in the last 15 min | `php artisan queue:failed`, fix, `queue:retry <id>` |
| horizon | not running / paused (redis queue only) | `supervisorctl status`, `supervisorctl restart horizon` |
| scheduler | no heartbeat for 10 min | cron entry for `schedule:run`; cron service |
| backup | no successful backup in 26 h | run `scripts/backup.sh` by hand, read its output |
| disk | < 10 % free | logs, old backups, `storage/app/exports` |

Run `php artisan ops:health` any time for the current table.
