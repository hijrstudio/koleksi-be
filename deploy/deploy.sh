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

# Batas thread OpenVZ (README "Batas thread OpenVZ"): sisa thread VPS bisa tinggal ±25 saat app & fe berjalan.
#  - GOMAXPROCS=1: CLI docker/compose (Go) tidak membuat thread sebanyak jumlah CPU (12). Tidak diteruskan ke
#    container (docker-compose.yml tidak mereferensikannya).
#  - pull bergantian: ekstraksi layer image app & fe tidak berjalan bersamaan.
#  - paket pigz sebaiknya tidak terpasang: containerd memakai unpigz (thread = jumlah CPU) untuk ekstraksi layer.
export GOMAXPROCS=1
docker compose pull app
docker compose pull fe
# Migrasi & RoleSeeder TIDAK lewat `compose run`: container ketiga di samping app & fe melampaui batas thread
# (runc abort, exit 134). docker/entrypoint.sh menjalankannya saat container app baru start (RUN_MIGRATIONS),
# sebelum web melayani request. </dev/null: compose bisa membaca stdin & menelan sisa skrip.
docker compose up -d --remove-orphans </dev/null

# `compose up` tidak selalu mendeteksi tag :latest yang berubah: ganti container yang image-nya tertinggal
for svc in app fe; do
  cid=$(docker compose ps -q "$svc" </dev/null)
  [ -n "$cid" ] || continue
  img=$(docker inspect -f '{{.Config.Image}}' "$cid")
  if [ "$(docker inspect -f '{{.Image}}' "$cid")" != "$(docker image inspect -f '{{.Id}}' "$img")" ]; then
    echo "image $svc berubah -> recreate"
    docker compose up -d --no-deps --force-recreate "$svc" </dev/null
  fi
done
docker image prune -f >/dev/null
docker compose ps </dev/null
