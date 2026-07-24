#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/rpg-api}"
WORKSPACE="${GITHUB_WORKSPACE:-$(pwd -P)}"
RELEASES="$APP_ROOT/releases"
SHARED="$APP_ROOT/shared"
CURRENT="$APP_ROOT/current"
STAMP="$(date +%Y%m%d%H%M%S)"
STAGING="$RELEASES/.staging-$STAMP-$$"
RELEASE="$RELEASES/$STAMP"
PREVIOUS="$(readlink -f "$CURRENT" 2>/dev/null || true)"

mkdir -p "$RELEASES" "$SHARED/storage/framework/cache/data" \
  "$SHARED/storage/framework/sessions" "$SHARED/storage/framework/views" \
  "$SHARED/storage/logs" "$SHARED/storage/app/public"
test -s "$SHARED/.env"

cleanup() { rm -rf "$STAGING"; }
trap cleanup EXIT

rsync -a --delete \
  --exclude='.git' --exclude='.env' --exclude='vendor' --exclude='storage' \
  "$WORKSPACE/" "$STAGING/"

cd "$STAGING"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
rm -rf storage
ln -s "$SHARED/storage" storage
ln -s "$SHARED/.env" .env
mkdir -p bootstrap/cache
chmod -R ug+rwX bootstrap/cache "$SHARED/storage"

php artisan config:cache
php artisan view:cache
php artisan migrate --force

mv "$STAGING" "$RELEASE"
ln -sfn "$RELEASE" "$CURRENT"

php "$CURRENT/artisan" queue:restart || true
sudo -n supervisorctl reread
sudo -n supervisorctl update
sudo -n supervisorctl restart rpg-api:*

if ! curl -kfsS --max-time 10 --resolve rpg-api.dogeow.com:443:127.0.0.1 \
  https://rpg-api.dogeow.com/up >/dev/null; then
  if [ -n "$PREVIOUS" ] && [ -d "$PREVIOUS" ]; then
    ln -sfn "$PREVIOUS" "$CURRENT"
    sudo -n supervisorctl restart rpg-api:*
  fi
  exit 1
fi

find "$RELEASES" -mindepth 1 -maxdepth 1 -type d -name '20*' -printf '%T@ %p\n' \
  | sort -nr | tail -n +2 | cut -d' ' -f2- | xargs -r rm -rf
