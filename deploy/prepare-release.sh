#!/usr/bin/env bash
# Run only on an authorized staging/release host with a fresh, reviewed artifact.
set -euo pipefail
: "${PHP_BIN:?Set the verified cPanel CLI PHP path}"
: "${RELEASE_DIR:?Set an absolute release directory outside public_html}"
: "${SHARED_DIR:?Set an absolute private shared directory}"
: "${BACKUP_RECEIPT:?Set the path to the verified database/upload backup receipt}"
RELEASE_DIR="$(realpath -e -- "$RELEASE_DIR")"
SHARED_DIR="$(realpath -e -- "$SHARED_DIR")"
[[ "$SHARED_DIR" =~ ^/home/[^/]+/builder/shared$ ]] || { echo 'Unexpected shared path'; exit 1; }
BUILDER_ROOT="$(dirname -- "$SHARED_DIR")"
case "$RELEASE_DIR" in "$BUILDER_ROOT"/releases/*) ;; *) echo 'Unexpected release path'; exit 1;; esac
RELEASE_ID="$(basename -- "$RELEASE_DIR")"
[[ "$RELEASE_ID" =~ ^[a-zA-Z0-9][a-zA-Z0-9_-]{0,100}$ ]] || { echo 'Unexpected release identity'; exit 1; }
test "$(dirname -- "$RELEASE_DIR")" = "$BUILDER_ROOT/releases"
test -f "$BACKUP_RECEIPT"
test -f "$SHARED_DIR/.env"
test -f "$RELEASE_DIR/vendor/autoload.php"
test ! -e "$RELEASE_DIR/.env"
test ! -L "$RELEASE_DIR/.env"
# Preflight must read the newly linked environment, never a stale config cache.
shopt -s nullglob dotglob
cache_entries=("$RELEASE_DIR/bootstrap/cache/"*)
((${#cache_entries[@]} == 0)) || { echo 'Release bootstrap cache must be empty'; exit 1; }
shopt -u nullglob dotglob
cd "$RELEASE_DIR"
# The artifact contains empty storage directories only. Link persistent data
# during the reviewed cPanel setup; never remove an existing storage tree here.
ln -s "$SHARED_DIR/.env" .env
chmod 600 "$SHARED_DIR/.env"
"$PHP_BIN" artisan builder:release-check --preflight --backup-receipt="$BACKUP_RECEIPT" --release-dir="$RELEASE_DIR" --shared-dir="$SHARED_DIR"
mkdir -p bootstrap/cache storage/framework/{cache/data,sessions,views} storage/logs storage/app/{private,public}
mkdir -p "$SHARED_DIR/media" "$SHARED_DIR/logs"
# rmdir only accepts empty artifact directories and safely refuses stored data.
rmdir storage/app/public storage/logs
ln -s "$SHARED_DIR/media" storage/app/public
ln -s "$SHARED_DIR/logs" storage/logs
"$PHP_BIN" artisan package:discover --ansi
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan db:seed --force
"$PHP_BIN" artisan storage:link
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan builder:release-check
echo 'Release prepared. Verify shared media storage, scheduler, SMTP, provider import, browser journeys and rollback before activating the document root.'
# Activation is deliberately a separate, explicitly authorized cPanel operation.
