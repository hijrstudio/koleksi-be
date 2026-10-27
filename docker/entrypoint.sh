#!/bin/sh
set -e

# Cache config/route/view dibuat saat start (bukan saat build) karena env baru tersedia di runtime.
php artisan storage:link --force >/dev/null 2>&1 || true
php artisan optimize

# Migrasi saat proses utama (supervisord: web + queue + scheduler) start, SEBELUM web melayani request.
# Di VPS (batas thread OpenVZ) tidak ada ruang untuk container `compose run` terpisah di samping app & fe.
# Perintah lain (`artisan tinker`, dll.) tidak memicu migrasi. Migrasi gagal → container tidak start (set -e).
if [ "${1:-}" = "supervisord" ] && [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
    # Idempoten (findOrCreate): permission modul CMS baru ikut terdaftar
    php artisan db:seed --class=RoleSeeder --force
fi

exec "$@"
