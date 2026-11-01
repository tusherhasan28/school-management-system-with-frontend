# Installing on Hostinger shared hosting (with Cloudflare)

Step-by-step install of the School SaaS on a **Hostinger Web Premium,
Business or Cloud** plan, with DNS and HTTPS through Cloudflare.

No Composer, Node or Redis is needed on Hostinger. The release zip already
contains everything.

Replace these placeholders everywhere:

| Placeholder | Example | Where to find it |
|---|---|---|
| `example.com` | `myschoolapp.com.bd` | your domain |
| `u123456789` | `u583920114` | hPanel → *Advanced → SSH Access* (the username) |
| `HOSTING_IP` | `145.223.x.x` | hPanel → *Hosting → Plan details* ("Website IP address") |

**How it looks when finished:**

- `https://example.com` — marketing page and school registration
- `https://admin.example.com` — your platform admin
- `https://<school>.example.com` — each school's website and app

> **Rehearsed before release.** Every step was run on a copy of Hostinger's
> setup: MariaDB 10.11, the `~/domains/example.com/public_html` folder layout,
> a database user limited to one database, no Redis, and cron commands run
> without a shell.
>
> These all worked:
> - install and platform login;
> - creating a school and opening its app in Bangla;
> - queued export, scheduler, encrypted backup and verified restore;
> - both ways of connecting `public_html` (step 6);
> - the update script.
>
> The full automated test suite also passes on MariaDB 10.11.

---

## Important: one manual step per school

Hostinger shared hosting **does not route wildcard subdomains** to your site.
Each school's address must be added once in hPanel (step 4), about a minute's
work.

The admin reminds you with the exact name when you approve a registration or
create a school. A new school can open its site once:

- its subdomain is added, and
- its SSL certificate shows *Active*.

If you expect many schools, a Hostinger VPS avoids this step entirely. See
`docs/deployment.md`.

---

## 0. Check your plan

| Need | Where |
|---|---|
| Web **Premium**, **Business** or **Cloud** plan. Single has no SSH | hPanel → *Hosting* |
| SSH turned on | hPanel → *Advanced → SSH Access* → **Enable** |
| PHP 8.3 | hPanel → *Advanced → PHP Configuration* (step 3) |
| ~150 MB disk, ~12,000 files (inodes) for the app | plenty on these plans |

## 1. Cloudflare: DNS

Your domain's nameservers point to Cloudflare. In Cloudflare → **DNS →
Records**, add these:

| Type | Name | Content | Proxy |
|---|---|---|---|
| A | `@` | `HOSTING_IP` | Proxied (orange) |
| A | `*` | `HOSTING_IP` | Proxied (orange) |

The `*` record sends every subdomain to Hostinger. Hostinger still has to
know about each subdomain (step 4).

**Email:** if you use Hostinger email, copy its records from hPanel →
*Emails → (your domain) → DNS settings*. That means the **MX** records, plus
**SPF**, **DKIM** and **DMARC** as TXT records. Add them in Cloudflare as
**DNS only** (grey cloud).

In hPanel, make sure **Hostinger CDN is off** for this website: *Performance →
CDN*. Two CDNs in a row cause redirect loops.

## 2. Cloudflare: SSL settings

1. **SSL/TLS → Overview**: set encryption mode to **Full**. You switch to
   **Full (strict)** in step 4 once Hostinger's certificates are active.
2. **SSL/TLS → Edge Certificates**: turn on **Always Use HTTPS**, and set the
   minimum TLS version to **1.2**.
3. **Speed → Optimization**: turn **Rocket Loader off**. The app's security
   policy blocks the scripts it injects, which breaks buttons.
4. **Scrape Shield**: turn **Email Address Obfuscation off**, for the same reason.

## 3. hPanel: PHP settings

hPanel → *Websites* → `example.com` → **Dashboard** → *Advanced → PHP
Configuration*.

1. **PHP version** tab: choose **8.3** and save.
2. **PHP extensions** tab: make sure these are ticked: `intl`, `zip`, `gd`,
   `exif`, `fileinfo`, `mbstring`, `pdo_mysql`, `xml`, `curl`, `openssl`.
   Most are on by default; `intl` sometimes isn't.
3. **PHP options** tab:
   - `upload_max_filesize` = **20M**
   - `post_max_size` = **22M**
   - `memory_limit` = **256M** or the highest offered
   - `max_execution_time` = **120**

   Then save.

The PHP used over SSH and by cron follows the **main domain** of the hosting
plan. If `example.com` is not the main domain and step 7 shows an old PHP
version, use the full path `/opt/alt/php83/usr/bin/php` instead of `php`
everywhere below.

## 4. hPanel: subdomains

hPanel → *Domains → Subdomains*. If you can't find it, search "Subdomains" in
the hPanel sidebar.

Create **`admin`** first:

1. Type `admin`.
2. Tick **Custom folder for subdomain**.
3. Set the folder to **`public_html`**, the main domain's own folder, so the
   subdomain shows the same app. If hPanel offers a "Use public_html
   directory" box, tick it.
4. Click **Create**.

Do the same later for every school, e.g. `rajuk-uttara`, using the same
`public_html` folder.

**SSL for each subdomain.** Hostinger installs a free certificate
automatically. Check it under hPanel → *Security → SSL*, and wait until the
subdomain shows **Active**. That usually takes a few minutes; DNS can take
longer.

If it shows **Failed**:

1. In Cloudflare, add an **A** record for that exact name (e.g. `admin`)
   pointing to `HOSTING_IP` with the **grey cloud**.
2. In hPanel → SSL, click **Install** or **Retry**.
3. When it shows Active, switch that record back to orange, or delete it (the
   `*` record covers it).

When `example.com` and `admin` both show Active, set Cloudflare SSL to
**Full (strict)**. For any later school whose certificate is still pending,
Cloudflare shows error 526 until it is Active.

## 5. Upload the release

1. hPanel → *Files → File Manager* → open **`domains/example.com/`**, the
   folder that *contains* `public_html`. Don't open public_html itself.
2. **Upload** `school-saas-<version>.zip` there.
3. Connect by SSH. hPanel → *Advanced → SSH Access* shows the command, e.g.
   `ssh -p 65002 u123456789@HOSTING_IP`. On Windows, use the Terminal app or
   PuTTY. Then run:

```bash
cd ~/domains/example.com
unzip -q school-saas-*.zip
mv school-saas-*/ school-saas
ls school-saas          # app  artisan  bootstrap  config  public  vendor ...
php -v                  # must say PHP 8.3
```

## 6. Point public_html at the app

The app's code, settings and uploads stay **outside** the web folder; only its
`public` folder is served.

**Method A (recommended): link.**

```bash
cd ~/domains/example.com
mv public_html public_html.old        # keeps Hostinger's default page, just in case
ln -s school-saas/public public_html
```

**Method B: copy.** Use this only if Method A gives "403 Forbidden" or a blank
page after step 7, or if hPanel recreates `public_html`.

```bash
cd ~/domains/example.com
rm public_html && mkdir public_html
~/domains/example.com/school-saas/scripts/publish-public.sh ~/domains/example.com/public_html
mkdir -p ~/.school-saas && chmod 700 ~/.school-saas
printf 'APP_DIR=%s\nPUBLIC_COPY_DIR=%s\n' ~/domains/example.com/school-saas ~/domains/example.com/public_html > ~/.school-saas/install.env
```

With Method B, the update script copies the new public files across
automatically (step 12).

## 7. Database and settings

1. hPanel → *Databases → Management*:
   - Create a database named `school`; it becomes `u123456789_school`.
   - Create user `app`; it becomes `u123456789_app`. Use a long generated
     password and save it in your password manager.
   - Hostinger gives that user full rights on that database.
2. Create the settings file:

```bash
cd ~/domains/example.com/school-saas
cp deploy/env.cpanel.example .env
chmod 600 .env
nano .env
```

Fill in every `<…>`. Ctrl+O saves, Ctrl+X exits.

| Setting | Value |
|---|---|
| `APP_URL` | `https://example.com` |
| `APP_DOMAIN` | `example.com` |
| `DB_HOST` | `localhost` |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | from step 7.1 |
| `TRUSTED_PROXIES` | `cloudflare` (already set) |
| `TENANCY_MANUAL_SUBDOMAINS` | **`true`** |
| `MAIL_HOST` | `smtp.hostinger.com` |
| `MAIL_PORT` | `465` |
| `MAIL_SCHEME` | `smtps` |
| `MAIL_USERNAME` | `no-reply@example.com` |
| `MAIL_PASSWORD` | create the mailbox first in hPanel → *Emails* |
| `MAIL_FROM_ADDRESS` | `no-reply@example.com` |
| `OPS_ALERT_EMAIL` | your own inbox |
| `OPS_HEALTH_TOKEN` | output of `openssl rand -hex 24` |
| `BILLING_PAYMENT_INSTRUCTIONS` | how schools pay you, in quotes |

If your plan has no Hostinger email, use any SMTP service (Brevo, Zoho,
Gmail with an app password) and put its host, port and login instead.

3. Install:

```bash
php artisan key:generate --force
php artisan migrate --force
php artisan optimize
php artisan ops:preflight
```

`ops:preflight` must end with **0 problem(s)**. Every FAIL line says what to
fix. After any `.env` change, run `php artisan optimize` again.

If it reports *proc_open … disabled*, use the alternative cron list in step 9.

**Save a copy of `.env` in your password manager.** Its `APP_KEY` decrypts
the schools' saved payment and SMS credentials.

## 8. Platform admin, then check the sites

```bash
php artisan platform:create-admin --name="Your Name" --email="you@example.com"
```

It asks for a password of 12 or more characters. Use a unique one from a
password manager; the platform login has no two-factor option yet.

Check each address:

- `https://example.com` → the marketing page.
- `https://admin.example.com` → sign in. Then create your plans under
  **Billing → Plans**.

## 9. Cron jobs

hPanel → *Advanced → **Cron Jobs*** → type **Custom** → schedule **every
minute** (`* * * * *`). Add these two:

```text
/usr/bin/php /home/u123456789/domains/example.com/school-saas/artisan schedule:run
```

```text
/usr/bin/php /home/u123456789/domains/example.com/school-saas/artisan queue:work database --queue=default,messages --stop-when-empty --max-time=50
```

Rules for Hostinger's cron box:

- **No** `>> /dev/null 2>&1`, **no** `cd … &&` and **no** variables.
  Hostinger does not run the command through a shell, so these would break
  it. It keeps the output itself; click **View output** to see it.
- Use the full paths exactly as above. If `php -v` showed an old version in
  step 5, use `/opt/alt/php83/usr/bin/php` instead of `/usr/bin/php`.
- Cron times are **UTC**. 02:40 in Dhaka is 20:40 UTC.

What they do:

- **The first** runs scheduled tasks: late fees and subscription billing
  daily, payment reconciliation every 15 minutes, and health checks with
  e-mail alerts every 5 minutes.
- **The second** processes queued work (imports, exports, SMS and e-mail) and
  exits when the queue is empty.

**Only if `ops:preflight` said `proc_open … disabled`:** replace the first
line with these five.

| Schedule | Command |
|---|---|
| `* * * * *` | `/usr/bin/php /home/u123456789/domains/example.com/school-saas/artisan ops:heartbeat` |
| `*/5 * * * *` | `/usr/bin/php /home/u123456789/domains/example.com/school-saas/artisan ops:health --alert` |
| `*/15 * * * *` | `/usr/bin/php /home/u123456789/domains/example.com/school-saas/artisan finance:run reconcile` |
| `30 19 * * *` | `/usr/bin/php /home/u123456789/domains/example.com/school-saas/artisan finance:run late-fees` |
| `15 20 * * *` | `/usr/bin/php /home/u123456789/domains/example.com/school-saas/artisan billing:run` |

## 10. Encrypted daily backup

Hostinger's own backups are good to have, but they are on Hostinger and not
under your control. Add your own encrypted backup:

```bash
mkdir -p ~/backups ~/.school-saas && chmod 700 ~/.school-saas
cd ~/.school-saas
openssl rand -base64 48 > backup.passphrase && chmod 600 backup.passphrase
printf '[client]\nuser=u123456789_app\npassword=YOUR_DB_PASSWORD\nhost=localhost\n' > backup.my.cnf && chmod 600 backup.my.cnf
cat > backup.env <<EOF
APP_DIR=$HOME/domains/example.com/school-saas
BACKUP_DIR=$HOME/backups
DB_DATABASE=u123456789_school
BACKUP_PASSPHRASE_FILE=$HOME/.school-saas/backup.passphrase
BACKUP_MYSQL_DEFAULTS=$HOME/.school-saas/backup.my.cnf
BACKUP_KEEP_DAYS=7
BACKUP_LOG=$HOME/backups/backup.log
PHP_BIN=/usr/bin/php
EOF
chmod 600 backup.env
cat backup.passphrase                  # copy into your password manager NOW
~/domains/example.com/school-saas/scripts/backup.sh
tail -3 ~/backups/backup.log           # ends with "backup: done"
```

Then add a cron job: **Custom**, schedule `40 20 * * *` (02:40 in Dhaka).

```text
/bin/bash /home/u123456789/domains/example.com/school-saas/scripts/backup.sh
```

**Off-server copy (strongly recommended).** Choose one:

- **Automatic** with rclone and Backblaze B2, Google Drive or similar:

  ```bash
  mkdir -p ~/bin && cd ~ && curl -sO https://downloads.rclone.org/rclone-current-linux-amd64.zip
  unzip -q rclone-current-linux-amd64.zip && cp rclone-*-linux-amd64/rclone ~/bin/ && rm -rf rclone-*
  ~/bin/rclone config                     # create a remote, e.g. "b2"
  echo 'BACKUP_RCLONE_REMOTE=b2:my-school-backups' >> ~/.school-saas/backup.env
  echo "PATH=$HOME/bin:/usr/bin:/bin" >> ~/.school-saas/backup.env
  ```

- **Manual:** once a week, download the newest folder in `~/backups` with
  File Manager. The files are encrypted.

**Test a restore once now, then every 3 months.**

1. In hPanel → *Databases*, create database `restore` for user
   `u123456789_app`.
2. Restore into it. The last line must say `OK: … tables … rows match`.

```bash
~/domains/example.com/school-saas/scripts/restore.sh ~/backups/<newest-folder> u123456789_restore ~/restore-test
```

3. Afterwards, delete that database in hPanel and run `rm -rf ~/restore-test`.

For a real restore, see `docs/operations.md`.

## 11. Monitoring

1. Run `php artisan ops:health`. Every line should be `ok` (the `disk` line
   reflects your plan's quota).
2. Set up a free uptime monitor (UptimeRobot, Better Stack) with checks every
   5 minutes on:
   - `https://example.com/up`
   - `https://admin.example.com/ops/health?token=YOUR_OPS_HEALTH_TOKEN`

   The second one fails when anything is wrong, including stopped cron jobs.
3. Alerts from the scheduled checks go to `OPS_ALERT_EMAIL`.

## 12. Adding a school

**Created by you:**

```bash
cd ~/domains/example.com/school-saas
php artisan school:create rajuk-uttara --name="Rajuk Uttara Model School" \
  --owner-name="Principal Name" --owner-email="principal@their-school.com" --status=trial
```

**Registered by the school itself:** they register at
`https://example.com/register-school`, and you approve them in the admin under
**Registrations**.

Either way, the app reminds you to:

1. Add the subdomain `rajuk-uttara` in hPanel, as in step 4, with the
   `public_html` folder.
2. Wait for its SSL to show **Active**.
3. Run `php artisan school:readiness rajuk-uttara` to see what the school
   still has to set up.
4. Give the principal the first password by phone. Assign a plan in the admin
   under *Schools → the school → Subscription*.
5. Follow `docs/pilot-onboarding.md` with the school office.

## 13. Updating to a new version

Upload the new zip to `~/domains/example.com/`, then run:

```bash
~/domains/example.com/school-saas/scripts/update.sh ~/domains/example.com/school-saas-<new-version>.zip
```

The script:

1. backs up;
2. shows a maintenance page;
3. installs the new version, keeping `.env` and all uploads;
4. migrates the database and rebuilds the caches;
5. brings the site back. This takes about a minute.

The old version stays in `school-saas.previous`, and the script prints the
rollback command.

## Troubleshooting

| Symptom | Fix |
|---|---|
| School address shows Hostinger's "domain not found / parked" page | its subdomain is not added in hPanel (step 4) |
| Cloudflare error **526** | that subdomain's SSL is not Active yet (step 4); meanwhile set Cloudflare to **Full** |
| "Too many redirects" | Cloudflare SSL is *Flexible* (use Full / Full strict), or Hostinger CDN is on |
| 403 or blank page on every address | use Method B in step 6 |
| "500 Server Error" | `tail -50 ~/domains/example.com/school-saas/storage/logs/laravel-*.log`; usually a `.env` typo → fix → `php artisan optimize` |
| Logged out right after login / "419 Page expired" | HTTPS must work on that address; keep `SESSION_DOMAIN=null`, `SESSION_SECURE_COOKIE=true` |
| Buttons do nothing, pages unstyled | Rocket Loader on (step 2) |
| Imports / SMS / exports stuck on "queued" | queue cron missing or wrong PHP path; *View output* in Cron Jobs; `php artisan ops:health` |
| Emails not delivered | `MAIL_*` values; SPF/DKIM records in Cloudflare |
| Uploads over 2 MB fail | PHP options (step 3) |

## When to move to a VPS

Shared hosting suits the pilot and the first schools. Move to a Hostinger VPS
(guide: `docs/deployment.md`; a backup + restore moves everything) when:

- adding subdomains by hand becomes a chore;
- you pass about 10–15 schools or 5,000 students;
- hPanel shows resource limits being hit at busy times (results day,
  fee deadlines).
