#!/usr/bin/env bash
# Deploy ulang FE & BE: tarik konfigurasi terbaru, unduh image dari ghcr.io, migrasi, lalu ganti container.
# Image dibangun GitHub Actions; jangan build di VPS (batas thread OpenVZ).
set -euo pipefail
cd "$(dirname "$0")"

set -a; . ./.env; set +a

git -C .. pull --ff-only

docker compose pull app fe
# </dev/null: `compose run` membaca stdin & bisa menelan sisa skrip
docker compose run --rm -T app php artisan migrate --force </dev/null
docker compose up -d --remove-orphans </dev/null
docker image prune -f >/dev/null
docker compose ps </dev/null
