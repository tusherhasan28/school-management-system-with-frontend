# Production deployment guide (VPS)

On cPanel shared hosting follow [install-cpanel.md](install-cpanel.md) instead.

A single Ubuntu 24.04 server is enough for the pilot (see sizing in
`docs/performance.md`). Example files are in `deploy/`; replace
`example.com.bd` with your domain (`APP_DOMAIN`).

## 1. Server and DNS

| Item | Pilot recommendation |
|---|---|
| Server | 4 vCPU / 8 GB RAM / 80 GB SSD (app + MySQL + Redis on one VPS); move MySQL to its own server past ~30 schools |
| OS packages | nginx, php8.3-fpm (+ `mysql`, `redis`, `mbstring`, `intl`, `gd`, `zip`, `bcmath`, `xml`, `curl`, `opcache`), mysql-server 8, redis-server, supervisor, git, composer, Node 22, rclone, certbot |
| DNS | `A example.com.bd`, `A *.example.com.bd` → server IP (the wildcard covers `admin.` and every school) |
| Firewall | `ufw allow OpenSSH; ufw allow 'Nginx Full'; ufw enable` — MySQL and Redis listen on 127.0.0.1 only |
| Clock | `timedatectl set-timezone UTC`; the app shows each school's own timezone |

TLS: a wildcard certificate needs a DNS-01 challenge, e.g. with Cloudflare DNS:

```bash
apt install certbot python3-certbot-dns-cloudflare
certbot certonly --dns-cloudflare --dns-cloudflare-credentials /root/.secrets/cloudflare.ini \
  -d example.com.bd -d '*.example.com.bd'
# renewal runs from certbot's systemd timer; add --deploy-hook "systemctl reload nginx"
```

## 2. Database and Redis

```sql
CREATE DATABASE school_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'school'@'localhost' IDENTIFIED BY '<long random>';
GRANT ALL ON school_saas.* TO 'school'@'localhost';
-- Backup user (read-only)
CREATE USER 'backup'@'localhost' IDENTIFIED BY '<long random>';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES ON school_saas.* TO 'backup'@'localhost';
GRANT PROCESS ON *.* TO 'backup'@'localhost';
```

MySQL: `innodb_buffer_pool_size` ≈ 50 % of RAM on a shared server;
`sql_mode` default (strict). Redis: `requirepass <long random>`, `bind 127.0.0.1`,
`maxmemory-policy noeviction` (queues and sessions live in Redis).

## 3. Application

Layout (handled by `scripts/deploy.sh`):

```
/var/www/school-saas/releases/<stamp>/   code per release
/var/www/school-saas/shared/.env         production settings (chmod 640, never in git)
/var/www/school-saas/shared/storage/     uploads + logs (backed up)
/var/www/school-saas/current -> releases/<stamp>
```

`shared/.env` — start from `.env.example` and change at least:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=                       # php artisan key:generate --show  (keep a copy in the password manager)
APP_URL=https://example.com.bd
APP_DOMAIN=example.com.bd
LOG_STACK=daily
LOG_LEVEL=warning
DB_PASSWORD=...
REDIS_PASSWORD=...
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=null            # host-only cookies: a school's session never reaches another school
MAIL_MAILER=smtp               # + MAIL_HOST / MAIL_USERNAME / MAIL_PASSWORD / MAIL_FROM_ADDRESS
OPS_ALERT_EMAIL=ops@your-company.com
OPS_HEALTH_TOKEN=              # openssl rand -hex 24
BILLING_PAYMENT_INSTRUCTIONS="..."
SECURITY_CSP_ENABLED=true
TRUSTED_PROXIES=cloudflare      # only when the site is behind Cloudflare
```

Do **not** set `MESSAGING_ALLOW_LOG_SMS` in production. Payment-gateway and SMS
credentials are entered per school in the app (stored encrypted), never in `.env`.

Install the example configs:

```bash
cp deploy/nginx.conf.example /etc/nginx/sites-available/school-saas   # edit domain, then link into sites-enabled
cp deploy/php-fpm-pool.conf.example /etc/php/8.3/fpm/pool.d/school-saas.conf
cp deploy/supervisor-horizon.conf.example /etc/supervisor/conf.d/school-saas-horizon.conf
crontab -u deploy deploy/crontab.example   # scheduler line; the backup line goes in root's crontab
```

First release:

```bash
sudo -u deploy DEPLOY_REPO=git@github.com:you/school-saas.git scripts/deploy.sh main
php artisan platform:create-admin --name="..." --email="..."   # platform super admin
supervisorctl reread && supervisorctl update                   # starts Horizon
```

Never run `db:seed` in production — the demo seeder refuses outside local/testing.
Plans are created in the platform area (Billing → Plans).

## 4. Backups and monitoring

1. `deploy/backup.env.example` → `/etc/school-saas/backup.env`; create the
   passphrase file and MySQL option file it names (chmod 600).
2. `rclone config` an off-server remote (Backblaze B2 / Wasabi / S3 / SFTP) and
   set `BACKUP_RCLONE_REMOTE`.
3. Run `scripts/backup.sh` once by hand, then the **restore drill** from
   `docs/operations.md` into a side database. Record the result.
4. External uptime monitor on `https://example.com.bd/up` and
   `https://admin.example.com.bd/ops/health?token=…` (alerts on 503).
5. `php artisan ops:health` must show every check `ok`.

## 5. Every release

```bash
sudo -u deploy DEPLOY_REPO=... scripts/deploy.sh <tag>
```

The script builds into a new directory, takes a backup, migrates, caches
(`php artisan optimize`), switches the symlink, reloads php-fpm and restarts
Horizon. Rollback of **code**: point `current` back to the previous release and
reload php-fpm. Migrations are forward-only; to undo a bad data migration,
restore the pre-deploy backup (`docs/operations.md`).

## 6. Go-live checklist

- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=null`
- [ ] HTTPS on root, `admin.` and a school subdomain; HTTP redirects to HTTPS; `Strict-Transport-Security` present
- [ ] `.env` not reachable over HTTP (`curl https://example.com.bd/.env` → 404)
- [ ] Platform admin uses a long unique password (platform 2FA is not built yet)
- [ ] Horizon running (`supervisorctl status`), scheduler cron installed, `ops:health` all ok
- [ ] Mail delivers (password reset to a real inbox)
- [ ] Backup ran, copy is off-server, restore drill recorded
- [ ] Uptime monitor and `OPS_ALERT_EMAIL` tested (pause Horizon → alert within 5 min)
- [ ] `composer audit` and `npm audit --omit=dev` clean
