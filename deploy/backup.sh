#!/usr/bin/env bash
# Backup harian DB + file upload ke ~/backups (simpan 14 hari). Pasang di crontab:
#   30 2 * * * bash ~/koleksi/be/deploy/backup.sh >> ~/backups/backup.log 2>&1
# Salin juga ke luar VPS (mis. `rclone copy ~/backups r2:koleksi-backup`), backup di disk yang sama tidak cukup.
set -euo pipefail
cd "$(dirname "$0")"
set -a; . ./.env; set +a

dir=~/backups
stamp=$(date +%F_%H%M)
mkdir -p "$dir"

docker compose exec -T mysql sh -c \
  'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE"' \
  | gzip > "$dir/db_$stamp.sql.gz"

docker compose exec -T app tar -czf - -C /app/storage app > "$dir/storage_$stamp.tar.gz"

find "$dir" -name '*.gz' -mtime +14 -delete
echo "backup $stamp ok"
