#!/usr/bin/env bash
# Deploy ulang FE & BE: tarik konfigurasi terbaru, unduh image dari ghcr.io, migrasi, lalu ganti container.
# Image dibangun GitHub Actions; jangan build di VPS (batas thread OpenVZ).
set -euo pipefail
cd "$(dirname "$0")"

if [ "${KOLEKSI_DEPLOY_UPDATED:-}" != 1 ]; then
  # Push ke koleksi-be & koleksi-fe bisa memicu deploy bersamaan: jalankan bergiliran.
  # Lock (fd 9) tetap dipegang setelah exec di bawah.
  exec 9>/tmp/koleksi-deploy.lock
  flock 9
  # git pull bisa mengubah skrip ini saat sedang dibaca bash: tarik dulu, lalu jalankan versi terbaru dari awal
  git -C .. pull --ff-only
  KOLEKSI_DEPLOY_UPDATED=1 exec bash "$0" "$@"
fi

set -a; . ./.env; set +a

docker compose pull app fe
# </dev/null: `compose run` membaca stdin & bisa menelan sisa skrip
docker compose run --rm -T app php artisan migrate --force </dev/null
docker compose up -d --remove-orphans </dev/null

# `compose up` tidak selalu mendeteksi tag :latest yang berubah: ganti container yang image-nya tertinggal
for svc in app fe; do
  cid=$(docker compose ps -q "$svc" </dev/null)
  img=$(docker compose config --images "$svc" </dev/null)
  if [ -n "$cid" ] && [ "$(docker inspect -f '{{.Image}}' "$cid")" != "$(docker image inspect -f '{{.Id}}' "$img")" ]; then
    echo "image $svc berubah -> recreate"
    docker compose up -d --no-deps --force-recreate "$svc" </dev/null
  fi
done
docker image prune -f >/dev/null
docker compose ps </dev/null
