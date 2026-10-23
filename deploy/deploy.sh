#!/usr/bin/env bash
# Deploy ulang FE & BE: tarik kode terbaru, build image, migrasi, lalu ganti container.
set -euo pipefail
cd "$(dirname "$0")"

# BACKEND_API_TOKEN dibutuhkan sebagai build secret fe
set -a; . ./.env; set +a

git -C .. pull --ff-only
git -C ../../fe pull --ff-only

docker compose build
docker compose up -d mysql redis
docker compose run --rm app php artisan migrate --force
docker compose up -d --remove-orphans
docker image prune -f
