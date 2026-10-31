#!/usr/bin/env bash
# Build a ready-to-upload release (for shared hosting / servers without Node):
# production Composer dependencies, generated Wayfinder routes and the built
# front-end, without node_modules, tests or git history.
#
#   scripts/build-release.sh [git-ref]      -> dist/school-saas-<ref>-<stamp>.zip
#
# Needs: git, PHP 8.3, Composer, Node 22, zip.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REF="${1:-HEAD}"
STAMP="$(date -u +%Y%m%d%H%M)"
NAME="school-saas-$(git -C "$ROOT" rev-parse --short "$REF")-$STAMP"
WORK="$(mktemp -d)"
OUT="$ROOT/dist"
trap 'rm -rf "$WORK"' EXIT

echo "build: exporting $REF"
mkdir -p "$WORK/$NAME" "$OUT"
git -C "$ROOT" archive "$REF" | tar -x -C "$WORK/$NAME"
cd "$WORK/$NAME"

echo "build: composer (production)"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-progress --quiet

echo "build: front-end"
cp .env.example .env
php artisan key:generate --quiet
php artisan wayfinder:generate --with-form >/dev/null
npm ci --no-audit --no-fund --silent
npm run build >/dev/null
rm -rf node_modules .env tests storage/logs/*.log bootstrap/cache/*.php
# Packages installed from source carry git history and their own test suites.
find vendor -mindepth 3 -maxdepth 3 \( -name .git -o -name .github -o -name tests -o -name Tests \) -type d -exec rm -rf {} +

# Empty runtime folders must exist on the server.
mkdir -p storage/app/private storage/app/public storage/framework/{cache/data,sessions,views,testing} storage/logs bootstrap/cache
for d in storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; do
  touch "$d/.gitkeep"
done

echo "build: packaging"
cd "$WORK"
zip -qr -9 "$OUT/$NAME.zip" "$NAME"
echo "build: $OUT/$NAME.zip ($(du -h "$OUT/$NAME.zip" | cut -f1))"
