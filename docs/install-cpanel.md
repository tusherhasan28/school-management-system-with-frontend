# Installing on cPanel shared hosting (with Cloudflare)

This guide installs the School SaaS on an ordinary cPanel hosting account,
with DNS and HTTPS on Cloudflare. No root access, Redis, Node or Composer is
needed on the server: the release zip already contains everything.

Throughout, replace:

| Placeholder | Example |
|---|---|
| `example.com` | your domain, e.g. `myschoolapp.com.bd` |
| `cpuser` | your cPanel username (shown at the top right of cPanel) |
| `SERVER_IP` | your hosting server's IP (cPanel → right sidebar "Shared IP Address") |

When it is finished you will have:

- `https://example.com` — marketing site and school registration
- `https://admin.example.com` — platform admin (you)
- `https://<school>.example.com` — one site + app per school

> Tested on a cPanel-like setup (Apache with `.htaccess`, PHP-FPM running as
> the account user, MySQL 8, **no Redis**): install, platform login, school
> creation, school app in Bangla, queued export via the cron worker, scheduler,
> encrypted backup + verified restore, and the update script.

---

## 0. Check your hosting first

Ask your host (or check in cPanel) that you have all of these. If one is
missing, the app will not run properly.

| Need | Where to check |
|---|---|
| **PHP 8.3** (or 8.4) | cPanel → *MultiPHP Manager* or *Select PHP Version* |
| PHP extensions: `pdo_mysql`, `mbstring`, `intl`, `zip`, `gd`, `exif`, `fileinfo`, `xml`, `curl`, `openssl` | *Select PHP Version* → Extensions |
| **MySQL 8.0+** or **MariaDB 10.6+** | cPanel home → right sidebar "Database" / *phpMyAdmin* |
| **Terminal** (or SSH) | cPanel → *Terminal* (Advanced section). Ask the host to enable SSH/Terminal if it is missing |
| **Cron jobs** | cPanel → *Cron Jobs* |
| `proc_open` not disabled | the installer check in step 6 tells you |
| ~1 GB disk to start | more as schools upload files |

A plan with at least 2 GB RAM / 2 CPU (e.g. "Business" or "Pro" shared
plans, or a CloudLinux plan with high limits) is recommended for 5–10 schools.
For more schools, move to a VPS (see `docs/deployment.md`).

---

## 1. Cloudflare: DNS records

In Cloudflare → your domain → **DNS → Records**, create:

| Type | Name | Content | Proxy |
|---|---|---|---|
| A | `@` | `SERVER_IP` | **Proxied** (orange cloud) |
| A | `*` | `SERVER_IP` | **Proxied** (orange cloud) |
| A | `mail` | `SERVER_IP` | DNS only (grey cloud) |

Keep the MX / SPF / DKIM records your host gave you (cPanel → *Email
Deliverability* shows the exact SPF and DKIM values; add them as TXT records in
Cloudflare, because cPanel cannot edit Cloudflare DNS).

`*` covers `admin.example.com` and every school's subdomain.

## 2. Cloudflare: SSL and settings

1. **SSL/TLS → Overview**: set encryption mode to **Full (strict)**.
2. **SSL/TLS → Origin Server → Create Certificate**: keep "RSA", hostnames
   `example.com` and `*.example.com`, validity 15 years → **Create**. Keep this
   page open: you need the *Origin Certificate* and the *Private Key* in step 4.
3. **SSL/TLS → Edge Certificates**: turn **Always Use HTTPS** on, minimum TLS
   version **1.2**.
4. **Speed → Optimization** (or *Settings*): turn **Rocket Loader off**. It
   rewrites scripts and the app's security policy blocks it, which would break
   pages.
5. **Scrape Shield**: turn **Email Address Obfuscation off** (same reason).

Cloudflare's free Universal SSL already covers `example.com` and
`*.example.com` for visitors.

## 3. cPanel: domain, wildcard subdomain and PHP

1. If `example.com` is an **addon domain** (not the account's main domain):
   cPanel → *Domains* → `example.com` → *Manage* → set **Document Root** to
   `school-saas/public`. Skip the public_html step in section 5.
   If it is the **main domain**, its document root is `public_html`; section 5
   replaces `public_html` with a link to the app.
2. cPanel → *Domains* → **Create A New Domain** → Domain: `*.example.com` →
   untick "Share document root" → Document Root: `school-saas/public` (for a
   main domain also enter `public_html`; both end at the same folder) →
   **Submit**.
3. cPanel → *MultiPHP Manager*: tick `example.com` and `*.example.com` → PHP
   **8.3** → Apply. (On CloudLinux: *Select PHP Version* → 8.3 → enable the
   extensions from step 0.)
4. cPanel → *MultiPHP INI Editor* → *Basic Mode* → choose the domain and set:
   `upload_max_filesize = 20M`, `post_max_size = 22M`, `memory_limit = 256M`,
   `max_execution_time = 120` → Apply.

## 4. cPanel: install the Cloudflare origin certificate

cPanel → *SSL/TLS* → **Manage SSL sites** → choose `example.com` → paste the
**Origin Certificate** into "Certificate (CRT)" and the **Private Key** into
"Private Key (KEY)" → **Install Certificate**. Repeat for `*.example.com`
(same certificate and key).

If your host's *AutoSSL* later replaces it: cPanel → *SSL/TLS Status* → select
`example.com` and `*.example.com` → **Exclude domains from AutoSSL**.
(If you cannot install a certificate at all, set Cloudflare to **Full** instead
of Full (strict); traffic stays encrypted but the server certificate is not
checked.)

## 5. Upload the application

1. cPanel → *File Manager* → your home folder (`/home/cpuser`, **not**
   public_html) → **Upload** the release zip `school-saas-<version>.zip`.
2. Open cPanel → **Terminal** and run:

```bash
cd ~
unzip -q school-saas-*.zip
mv school-saas-*/ school-saas      # the folder inside the zip
ls school-saas                     # app, artisan, public, vendor, ...
```

3. **Main domain only** — point `public_html` at the app's `public` folder.
   This moves anything already in public_html to `public_html.old`:

```bash
cd ~
mv public_html public_html.old
ln -s ~/school-saas/public ~/public_html
```

The app code, `.env` and uploaded files stay *outside* the web folder; only
`school-saas/public` is reachable from the internet.

## 6. Database and settings

1. cPanel → **MySQL Databases** (or *Manage My Databases*):
   - Create database: `school` → it becomes `cpuser_school`.
   - Create user: `app` → `cpuser_app`, with a long generated password (save it).
   - *Add User To Database* → `cpuser_app` + `cpuser_school` → **ALL PRIVILEGES**.
2. In the Terminal, create the settings file from the template:

```bash
cd ~/school-saas
cp deploy/env.cpanel.example .env
chmod 600 .env
nano .env
```

Fill every `<…>` (Ctrl+O to save, Ctrl+X to exit):

- `APP_URL=https://example.com` and `APP_DOMAIN=example.com`
- `DB_DATABASE=cpuser_school`, `DB_USERNAME=cpuser_app`, `DB_PASSWORD=…`
- Mail: cPanel → *Email Accounts* → create `no-reply@example.com` → *Connect
  Devices* shows the SMTP server (usually `mail.example.com`, port 465 SSL).
  Put its password in `MAIL_PASSWORD`.
- `OPS_ALERT_EMAIL` — your own inbox for alerts.
- `OPS_HEALTH_TOKEN` — run `openssl rand -hex 24` and paste the result.
- `BILLING_PAYMENT_INSTRUCTIONS` — how schools pay you (bKash merchant /
  bank account), in quotes.

3. Create the key, the tables and the caches, then run the check:

```bash
php artisan key:generate --force
php artisan migrate --force
php artisan optimize
php artisan ops:preflight
```

`ops:preflight` must show **0 problem(s)**. Fix any `FAIL` line (it says
how) and run it again. Typical fixes:

- *PHP version* or *extensions* → step 3.3.
- `php` in the Terminal is an older version → use the full path, e.g.
  `/opt/cpanel/ea-php83/root/usr/bin/php artisan …` (ask your host for the
  PHP 8.3 CLI path) and use the same path in the cron jobs below.
- *Database connection failed* → check `DB_*` in `.env`, then `php artisan optimize` again.

> After **any** change to `.env`, run `php artisan optimize` again.

**Keep a copy of `.env` in your password manager.** `APP_KEY` encrypts the
schools' gateway/SMS credentials; without it they cannot be read.

## 7. Create your platform admin and check the sites

```bash
php artisan platform:create-admin --name="Your Name" --email="you@example.com"
```

It asks for a password (12+ characters). Then open:

- `https://example.com` → the marketing page.
- `https://admin.example.com` → sign in with the admin account. (The
  platform login has no two-factor option yet: use a long unique password
  from a password manager.)
- `https://anything.example.com` → "not found" (no school yet) — that proves
  the wildcard works.

Create the plans you sell in **Billing → Plans** (e.g. Starter / Standard /
Campus with their student limits and prices).

## 8. Cron jobs (background work)

cPanel → **Cron Jobs** → set "Cron Email" to your address, then add these
(Common Settings: *Once Per Minute*). Use the same `php` path that worked in
step 6:

```text
* * * * *   php /home/cpuser/school-saas/artisan schedule:run >/dev/null 2>&1
* * * * *   php /home/cpuser/school-saas/artisan queue:work database --queue=default,messages --stop-when-empty --max-time=50 --sleep=3 >/dev/null 2>&1
```

- The first runs scheduled tasks: late fees and subscription billing (daily),
  payment reconciliation (15 min), health checks with email alerts (5 min).
- The second runs queued work: imports, exports, SMS/email messages. It starts
  every minute, works until the queue is empty and exits — no permanent
  process is needed.

**Only if `ops:preflight` said `proc_open … disabled`**, replace the first line
with one line per task:

```text
* * * * *     php /home/cpuser/school-saas/artisan ops:heartbeat >/dev/null 2>&1
*/5 * * * *   php /home/cpuser/school-saas/artisan ops:health --alert >/dev/null 2>&1
*/15 * * * *  php /home/cpuser/school-saas/artisan finance:run reconcile >/dev/null 2>&1
30 19 * * *   php /home/cpuser/school-saas/artisan finance:run late-fees >/dev/null 2>&1
15 20 * * *   php /home/cpuser/school-saas/artisan billing:run >/dev/null 2>&1
```

(Times are server time; 19:30/20:15 UTC = 01:30/02:15 in Dhaka. Check your
server's clock with `date`.)

## 9. Encrypted daily backup

Your host's own backups are not enough: they are usually on the same server
and not encrypted. In the Terminal:

```bash
mkdir -p ~/backups ~/.school-saas && chmod 700 ~/.school-saas
cd ~/.school-saas
openssl rand -base64 48 > backup.passphrase && chmod 600 backup.passphrase
printf '[client]\nuser=cpuser_app\npassword=YOUR_DB_PASSWORD\nhost=localhost\n' > backup.my.cnf && chmod 600 backup.my.cnf
cat > backup.env <<EOF
APP_DIR=$HOME/school-saas
BACKUP_DIR=$HOME/backups
DB_DATABASE=cpuser_school
BACKUP_PASSPHRASE_FILE=$HOME/.school-saas/backup.passphrase
BACKUP_MYSQL_DEFAULTS=$HOME/.school-saas/backup.my.cnf
BACKUP_KEEP_DAYS=7
PHP_BIN=php
EOF
chmod 600 backup.env
cat backup.passphrase      # copy this into your password manager NOW
BACKUP_ENV_FILE=$HOME/.school-saas/backup.env ~/school-saas/scripts/backup.sh
```

You should see `backup: done`. Add the cron job (daily, 02:40 Dhaka = 20:40 UTC):

```text
40 20 * * *  BACKUP_ENV_FILE=/home/cpuser/.school-saas/backup.env /home/cpuser/school-saas/scripts/backup.sh >> /home/cpuser/backups/backup.log 2>&1
```

**Off-server copy (strongly recommended).** If your account is lost, so are
backups kept on it. Either:

- *Automatic*: install rclone in your account and use a Backblaze B2, Wasabi or
  Google Drive remote:

  ```bash
  mkdir -p ~/bin && cd /tmp && curl -sO https://downloads.rclone.org/rclone-current-linux-amd64.zip
  unzip -q rclone-current-linux-amd64.zip && cp rclone-*-linux-amd64/rclone ~/bin/ && chmod 755 ~/bin/rclone
  ~/bin/rclone config            # create a remote, e.g. "b2"
  echo 'BACKUP_RCLONE_REMOTE=b2:my-school-backups' >> ~/.school-saas/backup.env
  echo 'PATH=$HOME/bin:$PATH' >> ~/.school-saas/backup.env
  ```

- *Manual*: once a week download the newest folder from `~/backups` with
  File Manager (the files are encrypted; keep them on your PC/drive).

**Test a restore once** (and every 3 months): cPanel → MySQL Databases →
create database `cpuser_restore` and add `cpuser_app` to it with ALL
PRIVILEGES, then:

```bash
BACKUP_ENV_FILE=$HOME/.school-saas/backup.env ~/school-saas/scripts/restore.sh ~/backups/<newest-folder> cpuser_restore ~/restore-test
```

It must end with `OK: … tables … rows match`. Afterwards delete the test
database in cPanel and `rm -rf ~/restore-test`. Full restore procedure:
`docs/operations.md`.

## 10. Monitoring

1. Check now: `php artisan ops:health` — every line `ok` (the `disk` line
   reflects your hosting quota).
2. Free uptime monitor (e.g. UptimeRobot, Better Stack): add two HTTP(S)
   monitors, every 5 minutes:
   - `https://example.com/up`
   - `https://admin.example.com/ops/health?token=YOUR_OPS_HEALTH_TOKEN`
     (returns an error when any check fails, including "the cron jobs stopped")
3. Alerts from the scheduled checks go to `OPS_ALERT_EMAIL`.

## 11. Your first school

```bash
cd ~/school-saas
php artisan school:create rajuk-uttara --name="Rajuk Uttara Model School" --owner-name="Principal Name" --owner-email="principal@their-school.com" --status=trial
php artisan school:readiness rajuk-uttara
```

The school is live at `https://rajuk-uttara.example.com`. Give the principal
the first password by phone, assign a plan in the admin (Schools → the school
→ Subscription), and follow `docs/pilot-onboarding.md` for setup with the
school office. Schools can also register themselves at
`https://example.com/register-school`; you approve them in the admin.

## 12. Updating to a new version

Upload the new release zip to your home folder, then:

```bash
~/school-saas/scripts/cpanel-update.sh ~/school-saas-<new-version>.zip
```

It backs up, shows a maintenance page, unpacks the new version, keeps `.env`
and all uploaded files, migrates the database, rebuilds the caches and brings
the site back (usually under a minute). The previous version stays in
`~/school-saas.previous`; the script prints the rollback command. Delete old
zips afterwards.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "500 Server Error" | `tail -50 ~/school-saas/storage/logs/laravel-*.log`; most often `.env` typos → fix, `php artisan optimize` |
| Every school shows "not found" | wildcard subdomain (3.2) missing, or `APP_DOMAIN` wrong |
| Redirect loop / "too many redirects" | Cloudflare SSL mode is *Flexible* → set **Full (strict)** |
| Logged out immediately / 419 errors | `SESSION_SECURE_COOKIE=true` needs HTTPS on every page; keep `SESSION_DOMAIN=null`; `php artisan optimize` |
| Pages load without styling or buttons do nothing | Rocket Loader is on (step 2.4), or `public/build` missing |
| Imports/SMS/exports stay "queued" | queue cron line missing or wrong `php` path; `php artisan ops:health` shows `queue_worker` |
| Emails not arriving | check `MAIL_*`; add SPF/DKIM TXT records in Cloudflare |
| Wrong visitor IP in logs / rate limits | `TRUSTED_PROXIES=cloudflare` in `.env`, then `php artisan optimize` |
| Uploads fail over 2 MB | MultiPHP INI Editor values (3.4) |

## Limits of shared hosting

Fine for a pilot and the first schools. Move to a VPS (`docs/deployment.md`)
when you pass roughly 10–15 schools or 5,000 students, when pages slow down at
peak times (results day), or if the host throttles CPU ("Resource Usage" in
cPanel). A backup and restore moves everything across.
