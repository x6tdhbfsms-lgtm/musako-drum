#!/bin/sh
set -eu
if [ "${APP_ENV:-}" != production ] || [ "${APP_DEBUG:-}" != false ]; then
    echo 'Oracle trial requires APP_ENV=production and APP_DEBUG=false.' >&2
    exit 1
fi
case "${APP_URL:-}" in https://*) ;; *) echo 'HTTPS APP_URL is required.' >&2; exit 1 ;; esac
[ -n "${APP_KEY:-}" ] || { echo 'Generate an Oracle-only APP_KEY first.' >&2; exit 1; }
mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
# Each container owns its bootstrap cache. No migration or seeding on startup.
php artisan config:cache
php artisan route:cache
php artisan view:cache
exec "$@"
