#!/usr/bin/env bash
# Encrypted backup of the database and uploaded files, with an integrity
# manifest, optional off-server copy (rclone) and local retention.
#
#   scripts/backup.sh                      # uses /etc/school-saas/backup.env
#   BACKUP_ENV_FILE=/path/backup.env scripts/backup.sh
#
# Required settings (environment or the env file; see deploy/backup.env.example):
#   BACKUP_DIR               local backup root, e.g. /var/backups/school-saas
#   BACKUP_PASSPHRASE_FILE   file holding the encryption passphrase (chmod 600)
#   BACKUP_MYSQL_DEFAULTS    MySQL option file with [client] user/password/host
#   DB_DATABASE              database to back up
# Optional:
#   APP_DIR                  application root (default: parent of this script)
#   PHP_BIN                  php binary (default: php)
#   BACKUP_KEEP_DAYS         local retention in days (default 14)
#   BACKUP_RCLONE_REMOTE     e.g. b2:school-saas-backups (off-server copy)
#   BACKUP_REMOTE_KEEP_DAYS  remote retention in days (default 35)
#
# Output: $BACKUP_DIR/<UTC stamp>/{database.sql.gz.enc, storage.tar.gz.enc,
# manifest.json, SHA256SUMS}. The status file read by `ops:health` is updated
# only after every step succeeded.
set -euo pipefail
umask 077

ENV_FILE="${BACKUP_ENV_FILE:-/etc/school-saas/backup.env}"
if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  set -a; . "$ENV_FILE"; set +a
fi

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
PHP_BIN="${PHP_BIN:-php}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
REMOTE_KEEP_DAYS="${BACKUP_REMOTE_KEEP_DAYS:-35}"

die() { echo "backup: $*" >&2; exit 1; }
log() { echo "backup: $(date -u +%H:%M:%S) $*"; }

: "${BACKUP_DIR:?BACKUP_DIR is not set}"
: "${BACKUP_PASSPHRASE_FILE:?BACKUP_PASSPHRASE_FILE is not set}"
: "${BACKUP_MYSQL_DEFAULTS:?BACKUP_MYSQL_DEFAULTS is not set}"
: "${DB_DATABASE:?DB_DATABASE is not set}"
[[ -s "$BACKUP_PASSPHRASE_FILE" ]] || die "passphrase file is missing or empty"
[[ -r "$BACKUP_MYSQL_DEFAULTS" ]] || die "MySQL option file is not readable"
[[ "$(stat -c %a "$BACKUP_PASSPHRASE_FILE")" =~ ^[4-7]00$ ]] || die "passphrase file must not be readable by group/others (chmod 600)"

encrypt() { openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass "file:$BACKUP_PASSPHRASE_FILE"; }

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="$BACKUP_DIR/$STAMP"
PARTIAL="$OUT.partial"
mkdir -p "$PARTIAL"
trap 'rm -rf "$PARTIAL"' ERR

log "manifest of $DB_DATABASE"
"$PHP_BIN" "$APP_DIR/artisan" ops:db-manifest --database="$DB_DATABASE" --output="$PARTIAL/manifest.json" >/dev/null

GTID=()
if mysqldump --help 2>/dev/null | grep -q -- '--set-gtid-purged'; then GTID=(--set-gtid-purged=OFF); fi

log "database dump"
mysqldump --defaults-extra-file="$BACKUP_MYSQL_DEFAULTS" \
  --single-transaction --quick --routines --triggers --events --hex-blob \
  --no-tablespaces --default-character-set=utf8mb4 "${GTID[@]}" \
  "$DB_DATABASE" | gzip -6 | encrypt > "$PARTIAL/database.sql.gz.enc"

log "uploaded files (storage/app)"
tar -C "$APP_DIR/storage" -czf - app | encrypt > "$PARTIAL/storage.tar.gz.enc"

( cd "$PARTIAL" && sha256sum database.sql.gz.enc storage.tar.gz.enc manifest.json > SHA256SUMS )
mv "$PARTIAL" "$OUT"
trap - ERR
log "written $OUT ($(du -sh "$OUT" | cut -f1))"

if [[ -n "${BACKUP_RCLONE_REMOTE:-}" ]]; then
  log "off-server copy to $BACKUP_RCLONE_REMOTE/$STAMP"
  rclone copy --immutable "$OUT" "$BACKUP_RCLONE_REMOTE/$STAMP"
  rclone check --one-way "$OUT" "$BACKUP_RCLONE_REMOTE/$STAMP"
  rclone delete --min-age "${REMOTE_KEEP_DAYS}d" "$BACKUP_RCLONE_REMOTE" || log "remote retention cleanup failed (continuing)"
  rclone rmdirs --leave-root "$BACKUP_RCLONE_REMOTE" >/dev/null 2>&1 || true
fi

find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name '20*Z' -mtime "+$KEEP_DAYS" -exec rm -rf {} +
find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name '*.partial' -mmin +1440 -exec rm -rf {} +

STATUS_FILE="${OPS_BACKUP_STATUS_FILE:-$APP_DIR/storage/app/ops/last-backup}"
mkdir -p "$(dirname "$STATUS_FILE")"
echo "$STAMP" > "$STATUS_FILE"
log "done"
