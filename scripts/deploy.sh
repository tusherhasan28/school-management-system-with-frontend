#!/usr/bin/env bash
# Zero-downtime-ish release: build into a new directory, migrate, switch the
# `current` symlink, reload php-fpm and restart Horizon.
#
#   scripts/deploy.sh <git-ref>        (run on the server as the deploy user)
#
# Layout:
#   /var/www/school-saas/releases/<stamp>   one checkout per release
#   /var/www/school-saas/shared/.env        production environment (never in git)
#   /var/www/school-saas/shared/storage     uploads, logs (survives releases)
#   /var/www/school-saas/current -> releases/<stamp>
set -euo pipefail

REF="${1:?usage: deploy.sh <git-ref>}"
BASE="${DEPLOY_BASE:-/var/www/school-saas}"
REPO="${DEPLOY_REPO:?set DEPLOY_REPO (git URL)}"
FPM_SERVICE="${FPM_SERVICE:-php8.3-fpm}"
KEEP="${DEPLOY_KEEP_RELEASES:-5}"
STAMP="$(date -u +%Y%m%d%H%M%S)"
REL="$BASE/releases/$STAMP"

echo "deploy: $REF -> $REL"
git clone --quiet --depth 1 --branch "$REF" "$REPO" "$REL"
cd "$REL"

ln -s "$BASE/shared/.env" .env
rm -rf storage && ln -s "$BASE/shared/storage" storage

composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci --no-audit --no-fund
php artisan wayfinder:generate --with-form
npm run build
rm -rf node_modules

# Backup first: migrations are forward-only in production.
if [[ -x "$BASE/current/scripts/backup.sh" ]]; then "$BASE/current/scripts/backup.sh"; fi

php artisan migrate --force
php artisan tenancy:sync-permissions
php artisan optimize          # config, routes, views, events
php artisan storage:link 2>/dev/null || true

ln -sfn "$REL" "$BASE/current.tmp" && mv -Tf "$BASE/current.tmp" "$BASE/current"
sudo systemctl reload "$FPM_SERVICE"
php "$BASE/current/artisan" horizon:terminate   # supervisor restarts it on the new code

ls -1dt "$BASE"/releases/* | tail -n +$((KEEP + 1)) | xargs -r rm -rf
php "$BASE/current/artisan" ops:health || true
echo "deploy: done ($STAMP)"
