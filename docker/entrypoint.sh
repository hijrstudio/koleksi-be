#!/bin/sh
set -e

# Cache config/route/view dibuat saat start (bukan saat build) karena env baru tersedia di runtime.
php artisan storage:link --force >/dev/null 2>&1 || true
php artisan optimize

exec "$@"
