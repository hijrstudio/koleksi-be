#!/usr/bin/env bash
# Backup harian DB (MariaDB Webuzo) + file upload ke ~/backups (simpan 14 hari). Pasang di crontab:
#   30 2 * * * bash ~/koleksi/be/deploy/backup.sh >> ~/backups/backup.log 2>&1
# Salin juga ke luar VPS (mis. `rclone copy ~/backups r2:koleksi-backup`), backup di disk yang sama tidak cukup.
set -euo pipefail
cd "$(dirname "$0")"
set -a; . ./.env; set +a

dir=~/backups
stamp=$(date +%F_%H%M)
mkdir -p "$dir"

dump=$(command -v mysqldump || command -v mariadb-dump || ls /usr/local/apps/mariadb*/bin/mariadb-dump 2>/dev/null | head -1)
# Password lewat file sementara, bukan argumen (tidak terlihat di `ps`)
cnf=$(mktemp); trap 'rm -f "$cnf"' EXIT; chmod 600 "$cnf"
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "${DB_HOST:-127.0.0.1}" "${DB_PORT:-3306}" "$DB_USERNAME" "$DB_PASSWORD" > "$cnf"
"$dump" --defaults-extra-file="$cnf" --single-transaction --routines --triggers "$DB_DATABASE" | gzip > "$dir/db_$stamp.sql.gz"

docker compose exec -T app tar -czf - -C /app/storage app </dev/null > "$dir/storage_$stamp.tar.gz"

find "$dir" -name '*.gz' -mtime +14 -delete
echo "backup $stamp ok"
