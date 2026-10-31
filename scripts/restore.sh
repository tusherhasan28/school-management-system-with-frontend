#!/usr/bin/env bash
# Restore a backup made by scripts/backup.sh into a database (and optionally a
# storage directory), then prove it matches the backup manifest.
#
#   scripts/restore.sh <backup-dir> <target-database> [<storage-target-dir>]
#
# Uses the same settings as backup.sh (BACKUP_PASSPHRASE_FILE,
# BACKUP_MYSQL_DEFAULTS; RESTORE_MYSQL_DEFAULTS overrides the latter when the
# restore needs a user that can create databases).
#
# Safety: refuses to restore over the live database (DB_DATABASE) or into a
# non-empty database unless RESTORE_ALLOW_OVERWRITE=1. Restoring over live data
# is a last resort: restore into a new database first, verify, then switch
# DB_DATABASE (see docs/operations.md).
set -euo pipefail
umask 077

ENV_FILE="${BACKUP_ENV_FILE:-/etc/school-saas/backup.env}"
if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  set -a; . "$ENV_FILE"; set +a
fi

die() { echo "restore: $*" >&2; exit 1; }
log() { echo "restore: $(date -u +%H:%M:%S) $*"; }

[[ $# -ge 2 ]] || die "usage: $0 <backup-dir> <target-database> [<storage-target-dir>]"
SRC="$1"; TARGET="$2"; STORAGE_TARGET="${3:-}"
APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
PHP_BIN="${PHP_BIN:-php}"
DEFAULTS="${RESTORE_MYSQL_DEFAULTS:-${BACKUP_MYSQL_DEFAULTS:?BACKUP_MYSQL_DEFAULTS is not set}}"
: "${BACKUP_PASSPHRASE_FILE:?BACKUP_PASSPHRASE_FILE is not set}"

[[ "$TARGET" =~ ^[A-Za-z0-9_]+$ ]] || die "database name may only contain letters, digits and underscores"
[[ -f "$SRC/SHA256SUMS" ]] || die "$SRC is not a backup directory"

if [[ "$TARGET" == "${DB_DATABASE:-}" && "${RESTORE_ALLOW_OVERWRITE:-0}" != "1" ]]; then
  die "refusing to restore over the live database $TARGET (set RESTORE_ALLOW_OVERWRITE=1 to force)"
fi

decrypt() { openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$BACKUP_PASSPHRASE_FILE"; }

log "checking file integrity"
( cd "$SRC" && sha256sum --quiet -c SHA256SUMS ) || die "checksum mismatch: backup is damaged or was altered"

mysql --defaults-extra-file="$DEFAULTS" -e "CREATE DATABASE IF NOT EXISTS \`$TARGET\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
TABLES="$(mysql --defaults-extra-file="$DEFAULTS" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$TARGET'")"
if [[ "$TABLES" != "0" && "${RESTORE_ALLOW_OVERWRITE:-0}" != "1" ]]; then
  die "database $TARGET already has $TABLES tables (set RESTORE_ALLOW_OVERWRITE=1 to replace them)"
fi

log "importing database into $TARGET"
START=$(date +%s)
decrypt < "$SRC/database.sql.gz.enc" | gunzip | mysql --defaults-extra-file="$DEFAULTS" --default-character-set=utf8mb4 "$TARGET"
log "imported in $(( $(date +%s) - START ))s"

if [[ -n "$STORAGE_TARGET" ]]; then
  log "extracting uploaded files into $STORAGE_TARGET"
  mkdir -p "$STORAGE_TARGET"
  decrypt < "$SRC/storage.tar.gz.enc" | tar -xzf - -C "$STORAGE_TARGET"
fi

log "verifying against the manifest"
"$PHP_BIN" "$APP_DIR/artisan" ops:verify-restore "$SRC/manifest.json" --database="$TARGET" ${RESTORE_VERIFY_IGNORE:-}
log "done"
