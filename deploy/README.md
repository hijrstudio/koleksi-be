# Deploy VPS (FE + BE)

Satu VPS menjalankan seluruh stack lewat Docker Compose:

```
Internet ─► caddy (80/443, HTTPS otomatis)
              ├─ FE_DOMAIN   ─► fe   (Next.js standalone, :3000)
              └─ API_DOMAIN  ─► app  (Laravel + FrankenPHP, :8080)
            worker (queue:work) · scheduler (schedule:work) · mysql 8.4 · redis 7
```

File upload (disk `public`) disimpan di volume `storage` dan dilayani di `https://API_DOMAIN/storage/...`.

## 1. Siapkan VPS (sekali)

Ubuntu 24.04, minimal 2 vCPU / 4 GB RAM. Akun VPS atas nama klien.

```bash
# sebagai root
adduser deploy && usermod -aG sudo deploy
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy
# /etc/ssh/sshd_config: PermitRootLogin no, PasswordAuthentication no
systemctl restart ssh

ufw allow OpenSSH && ufw allow 80 && ufw allow 443 && ufw enable
apt update && apt upgrade -y && apt install -y git unattended-upgrades fail2ban

curl -fsSL https://get.docker.com | sh
usermod -aG docker deploy
```

Port MySQL/Redis sengaja tidak di-publish. Jangan tambahkan `ports:` untuk keduanya, karena Docker melewati aturan UFW.

## 2. DNS

| Record | Nama  | Nilai   |
|--------|-------|---------|
| A      | `@`   | IP VPS  |
| A      | `www` | IP VPS  |
| A      | `api` | IP VPS  |

Tunggu sampai `dig +short api.domain.com` mengembalikan IP VPS sebelum langkah 4, karena Caddy butuh DNS aktif untuk menerbitkan SSL.

## 3. Clone & isi env

```bash
su - deploy
mkdir ~/koleksi && cd ~/koleksi
git clone git@github.com:<org>/koleksi-be.git be     # pakai deploy key read-only
git clone git@github.com:<org>/koleksi-fe.git fe

cp be/deploy/.env.example be/deploy/.env && nano be/deploy/.env   # domain, DB, token
cp be/.env.example be/.env && nano be/.env                         # env Laravel
```

`be/.env`: isi seperti `.env.sample.deploy`, dengan catatan:

- `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`, `APP_KEY=` (lihat langkah 4).
- `APP_URL`, `FRONTEND_URL`, `FRONTEND_API_TOKEN`, `DB_*`, `REDIS_*`, `CACHE_STORE`, `QUEUE_CONNECTION` dan `SESSION_DRIVER` **tidak perlu diisi**. Semuanya diisi otomatis dari `deploy/.env` oleh `docker-compose.yml`.
- Isi SMTP, `SUPERADMIN_*` dan `YODU_*` **production** (`YODU_BASE_URL=https://yodupay-api.yodu.id`, key asli via `YODU_PRIVATE_KEY` base64). Jangan pakai key sandbox.

## 4. Deploy pertama

```bash
cd ~/koleksi/be/deploy
set -a; . ./.env; set +a

# APP_KEY: generate lalu tempel ke be/.env
docker compose build app
docker compose run --rm --no-deps --entrypoint php app artisan key:generate --show

# Backend dulu (build fe butuh API yang sudah online untuk prerender)
docker compose up -d mysql redis
docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan db:seed --force   # hanya kalau DB baru & butuh seeder
docker compose up -d app worker scheduler caddy

curl -I https://api.domain.com/up      # harus 200

# Frontend
docker compose up -d --build fe
```

Deploy selanjutnya cukup `bash ~/koleksi/be/deploy/deploy.sh`.

## 5. Auto-deploy dari GitHub

Workflow `.github/workflows/deploy-vps.yml` ada di **kedua** repo dan menjalankan `deploy.sh` lewat SSH. Di masing-masing repo GitHub, buka Settings → Secrets and variables → Actions:

- Secrets: `VPS_HOST`, `VPS_USER` (`deploy`), `VPS_SSH_KEY` (private key khusus CI, public key-nya di `~deploy/.ssh/authorized_keys`).
- Variables: `VPS_DEPLOY` = `true`. Selama belum di-set, workflow di-skip.

## 6. Backup

```bash
crontab -e
30 2 * * * bash ~/koleksi/be/deploy/backup.sh >> ~/backups/backup.log 2>&1
```

Salin `~/backups` ke luar VPS (rclone ke R2/S3/Google Drive), aktifkan snapshot dari provider VPS, dan uji restore minimal sekali:

```bash
gunzip -c db_XXXX.sql.gz | docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
docker compose exec -T app tar -xzf - -C /app/storage < storage_XXXX.tar.gz
```

## 7. Migrasi dari Laravel Cloud + Vercel

1. Turunkan TTL DNS ke 300 detik sehari sebelumnya.
2. Export DB dari Laravel Cloud, lalu import ke VPS (perintah restore di atas).
3. Salin isi bucket Laravel Cloud ke `storage/app/public`, misalnya `rclone copy <bucket> ./public`, lalu `docker compose cp ./public app:/app/storage/app/`, lalu `docker compose exec -u root app chown -R app:app /app/storage`. URL file berubah ke `https://API_DOMAIN/storage/...`.
4. Pindahkan record DNS ke IP VPS, lalu tes login CMS, login member, upload, dan pembayaran QRIS (callback YODU harus mengarah ke `https://API_DOMAIN`).
5. Biarkan Laravel Cloud & Vercel hidup 1–2 hari sebagai rollback, lalu matikan auto-deploy keduanya.

## Operasional

```bash
cd ~/koleksi/be/deploy
docker compose ps
docker compose logs -f app worker scheduler fe
docker compose exec app php artisan tinker
docker compose restart worker
```

## Scaling

1. Upgrade spesifikasi VPS (vertical).
2. Pindahkan MySQL ke managed DB atau VPS terpisah: hapus service `mysql`, lalu arahkan `DB_HOST`.
3. Pindahkan upload ke S3/R2 (isi `BACKEND_STORAGE_URL`), supaya `app` bisa dijalankan di lebih dari satu server di belakang load balancer.
