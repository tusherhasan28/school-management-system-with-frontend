#!/usr/bin/env bash
# Update an installation on shared hosting from a release zip
# (built with scripts/build-release.sh), keeping .env and uploaded files.
#
#   ~/school-saas/scripts/cpanel-update.sh ~/school-saas-<version>.zip
#
# Steps: backup → maintenance mode → unpack next to the current copy → carry
# over .env and storage → swap folders → migrate → cache → back online.
# The previous copy stays as ~/school-saas.previous for a quick rollback.
set -euo pipefail

ZIP="${1:?usage: cpanel-update.sh <release.zip>}"
APP="${APP_DIR:-$HOME/school-saas}"
PHP="${PHP_BIN:-php}"
NEW="$APP.new"
OLD="$APP.previous"

[[ -f "$ZIP" ]] || { echo "update: $ZIP not found" >&2; exit 1; }
[[ -f "$APP/.env" ]] || { echo "update: $APP/.env not found" >&2; exit 1; }

echo "update: backup first"
if [[ -n "${BACKUP_ENV_FILE:-}" || -f "$HOME/.school-saas/backup.env" ]]; then
  BACKUP_ENV_FILE="${BACKUP_ENV_FILE:-$HOME/.school-saas/backup.env}" "$APP/scripts/backup.sh"
else
  echo "update: no backup settings found (~/.school-saas/backup.env); continuing without a backup" >&2
fi

echo "update: unpacking"
rm -rf "$NEW" "$NEW.tmp"
mkdir -p "$NEW.tmp"
unzip -q "$ZIP" -d "$NEW.tmp"
mv "$NEW.tmp"/school-saas-* "$NEW"
rmdir "$NEW.tmp"

cp -p "$APP/.env" "$NEW/.env"
rm -rf "$NEW/storage"

"$PHP" "$APP/artisan" down --retry=60 || true
# Uploaded files, logs and sessions move with the app (same disk, instant).
mv "$APP/storage" "$NEW/storage"

rm -rf "$OLD"
mv "$APP" "$OLD"
mv "$NEW" "$APP"

cd "$APP"
"$PHP" artisan migrate --force
"$PHP" artisan tenancy:sync-permissions
"$PHP" artisan optimize
"$PHP" artisan queue:restart
"$PHP" artisan up
"$PHP" artisan ops:preflight || true
echo "update: done. Rollback: php artisan down; mv $APP $APP.failed; mv $OLD $APP; mv $APP.failed/storage $APP/storage; php $APP/artisan up"
