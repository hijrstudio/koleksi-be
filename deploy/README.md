# Deploy VPS (FE + BE)

Server produksi: **VPS Hosting Jagoan Hosting** (OpenVZ, NAT, panel Webuzo). Webuzo tidak boleh dimatikan,
jadi Apache bawaan Webuzo tetap memegang port 80/443 + SSL, dan meneruskan request ke container Docker:

```
Internet ─► Domain Forward Jagoan (IP 101.50.1.14) ─► Apache Webuzo :80/:443 (SSL Let's Encrypt Webuzo)
              ├─ koleksi.club, www  ─► 127.0.0.1:3000 ─► fe   (Next.js)
              ├─ api.koleksi.club   ─► 127.0.0.1:8080 ─► app  (Laravel: hanya /api, /storage, /up)
              └─ cms.koleksi.club   ─► 127.0.0.1:8080 ─► app  (Laravel: hanya CMS admin)
            Docker: worker (queue) · scheduler · mysql 8.4 · redis 7  (tidak terbuka ke luar)
```

API dan CMS adalah aplikasi Laravel & container yang sama; Apache memisahkannya per subdomain:

| Request | `api.koleksi.club` | `cms.koleksi.club` |
|---|---|---|
| `/api/*` (FE, callback YODU, link verifikasi e-mail) | ✅ diteruskan | 404 |
| `/api/auth/*` (login CMS via API, tidak dipakai) | 404 | 404 |
| `/storage/*`, `/up` | ✅ diteruskan | ✅ diteruskan |
| Halaman CMS (`/`, `/signin`, `/members`, …) | 302 → `cms.` (link e-mail admin dibuat dari `APP_URL`) | ✅ diteruskan |

Cookie session CMS hanya berlaku di `cms.koleksi.club`. Kedua subdomain dikirim dengan `X-Robots-Tag: noindex`.

- Konfigurasi proxy Apache: [`webuzo/`](webuzo/), dipasang di `/var/webuzo-data/apache2/custom/domains/`
  (lokasi resmi Webuzo untuk custom config per domain, tidak ditimpa saat Webuzo membuat ulang vhost).
- File upload (disk `public`) disimpan di volume Docker `storage`, dilayani di `https://api.koleksi.club/storage/...`.
- Server tanpa panel (VPS KVM / IP dedicated)? Lihat [Tanpa Webuzo](#tanpa-webuzo-caddy).

## 0. Akses server

| | |
|---|---|
| SSH | `ssh -p 50012 deploy@101.50.1.14` (atau `ssh koleksi` dengan `~/.ssh/config`), **hanya SSH key** |
| Login root lewat SSH | dimatikan, pakai `sudo` dari user `deploy` |
| Darurat (key hilang) | console VNC di member area Jagoan Hosting |
| Panel Webuzo | tertutup firewall, buka lewat SSH tunnel (langkah 3) |

Hardening yang sudah terpasang: user `deploy` (grup `sudo`, `docker`), UFW (hanya 22/80/443), fail2ban,
unattended-upgrades, `/etc/ssh/sshd_config.d/00-hardening.conf` (`PermitRootLogin no`, `PasswordAuthentication no`).

Docker sudah terpasang (`curl -fsSL https://get.docker.com | sh`) dan lolos tes jaringan di OpenVZ (cgroup v1).
Jangan upgrade Docker sembarangan: cgroup v1 deprecated, cek release notes dulu.

## 1. DNS (sudah)

Nameserver masih di Wix (`ns4/ns5.wixdns.net`) selama transfer domain ke Jagoan Hosting.

| Type | Name | Value | Priority |
|---|---|---|---|
| A | `@` | `101.50.1.14` | |
| A | `api` | `101.50.1.14` | |
| A | `cms` | `101.50.1.14` | |
| A | `mail` | `152.44.45.11` | |
| CNAME | `www` | `koleksi.club` | |
| MX | `@` | `mx.zoho.com` | 10 |
| MX | `@` | `mx2.zoho.com` | 20 |
| MX | `@` | `mx3.zoho.com` | 50 |
| TXT | `@` | `v=spf1 include:zohomail.com ~all` | |
| TXT | `@` | `zoho-verification=zb13674199.zmverify.zoho.com` | |

**Begitu transfer selesai:** buat ulang semua record di atas di DNS baru, **lalu** ganti nameserver.
Wix menghapus zona DNS domain yang pindah, jadi website **dan email Zoho** mati kalau ini dilewati.

## 2. Domain Forward Jagoan (sudah)

Member area → VPS → Domain Forward: HTTP (80) & HTTPS (443) untuk `koleksi.club`, `www.koleksi.club`,
`api.koleksi.club`, `cms.koleksi.club`. Port 2002–2005 adalah panel Webuzo, dikunci provider (tidak bisa dihapus).

## 3. Webuzo: domain + SSL

Buka panel lewat SSH tunnel dari laptop (port panel tidak dibuka di firewall):

```powershell
ssh -L 2005:127.0.0.1:2005 -L 2003:127.0.0.1:2003 koleksi
```

Biarkan terminal itu terbuka, lalu di browser (abaikan peringatan sertifikat):

1. **Admin panel** `https://localhost:2005`, login `root` + password root VPS.
   Tambah user baru dengan domain utama `koleksi.club`.
2. **User panel** `https://localhost:2003`, login user tadi.
   Tambah subdomain `api.koleksi.club` dan `cms.koleksi.club`.
3. Pasang SSL **Let's Encrypt** untuk `koleksi.club`, `www.koleksi.club`, `api.koleksi.club`, dan `cms.koleksi.club`
   ([panduan Jagoan](https://www.jagoanhosting.com/tutorial/vps-hosting/cara-install-ssl-domain-pada-layanan-vps-hosting)).
   Perpanjangan otomatis oleh cron Webuzo (`/etc/cron.d/lets_encrypt`), jangan dihapus.

Cek dari laptop: `https://koleksi.club`, `https://api.koleksi.club` & `https://cms.koleksi.club` harus tampil dengan gembok valid
(isi halaman masih default Webuzo, itu normal).

## 4. Clone & isi env

```bash
mkdir -p ~/koleksi && cd ~/koleksi
git clone <url-repo-koleksi-be> be
git clone <url-repo-koleksi-fe> fe

cp be/deploy/.env.example be/deploy/.env && nano be/deploy/.env   # domain, DB, token
cp be/.env.example be/.env && nano be/.env                         # env Laravel
```

`be/.env`: isi seperti `.env.sample.deploy`, dengan catatan:

- `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`, `APP_KEY=` (lihat langkah 5).
- **Tidak perlu diisi** (otomatis dari `deploy/.env` oleh `docker-compose.yml`): `APP_URL`, `FRONTEND_URL`,
  `FRONTEND_API_TOKEN`, `DB_*`, `REDIS_*`, `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER`.
- SMTP, `SUPERADMIN_*`, dan `YODU_*` **production** (`YODU_BASE_URL=https://yodupay-api.yodu.id`,
  key asli lewat `YODU_PRIVATE_KEY` base64). Jangan pakai key sandbox.

MySQL & Redis jalan di dalam Docker. **Jangan** pakai MariaDB bawaan Webuzo (port 3306 host).

## 5. Deploy pertama

Urutan penting: build `fe` memanggil `https://api.koleksi.club` (prerender ISR), jadi API + SSL harus online dulu.

```bash
cd ~/koleksi/be/deploy
set -a; . ./.env; set +a

# APP_KEY: generate lalu tempel ke be/.env
docker compose build app
docker compose run --rm --no-deps --entrypoint php app artisan key:generate --show

# Backend
docker compose up -d mysql redis
docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan db:seed --force   # hanya kalau DB baru & butuh seeder
docker compose up -d app worker scheduler
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/up   # harus 200

# Reverse proxy Apache Webuzo -> container
sudo mkdir -p /var/webuzo-data/apache2/custom/domains
sudo cp ~/koleksi/be/deploy/webuzo/*.conf /var/webuzo-data/apache2/custom/domains/
sudo /usr/local/apps/apache2/bin/apachectl -t && sudo systemctl restart httpd
curl -s -o /dev/null -w '%{http_code}\n' https://api.koleksi.club/up   # harus 200
curl -s -o /dev/null -w '%{http_code}\n' https://cms.koleksi.club/signin   # harus 200 (login CMS)

# Frontend
docker compose up -d --build fe
curl -s -o /dev/null -w '%{http_code}\n' https://koleksi.club/          # harus 200
```

Deploy selanjutnya cukup `bash ~/koleksi/be/deploy/deploy.sh`.
Kalau isi `deploy/webuzo/*.conf` berubah, salin ulang ke `/var/webuzo-data/apache2/custom/domains/` + restart `httpd`.

## 6. Auto-deploy dari GitHub

Workflow `.github/workflows/deploy-vps.yml` ada di **kedua** repo dan menjalankan `deploy.sh` lewat SSH.
Di masing-masing repo, buka Settings → Secrets and variables → Actions:

- Secrets: `VPS_HOST` (`101.50.1.14`), `VPS_PORT` (`50012`), `VPS_USER` (`deploy`), `VPS_SSH_KEY`
  (private key khusus CI, public key-nya di `~deploy/.ssh/authorized_keys`).
- Variables: `VPS_DEPLOY` = `true`. Selama belum di-set, workflow di-skip.

## 7. Backup

```bash
crontab -e
30 2 * * * bash ~/koleksi/be/deploy/backup.sh >> ~/backups/backup.log 2>&1
```

Disk hanya 40 GB: salin `~/backups` ke luar VPS (rclone ke R2/S3/Google Drive), dan uji restore minimal sekali:

```bash
gunzip -c db_XXXX.sql.gz | docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
docker compose exec -T app tar -xzf - -C /app/storage < storage_XXXX.tar.gz
```

## 8. Migrasi dari Laravel Cloud + Vercel

1. Export DB dari Laravel Cloud, lalu import ke VPS (perintah restore di atas).
2. Salin isi bucket Laravel Cloud ke `storage/app/public`, misalnya `rclone copy <bucket> ./public`, lalu
   `docker compose cp ./public app:/app/storage/app/` dan `docker compose exec -u root app chown -R app:app /app/storage`.
   URL file berubah ke `https://api.koleksi.club/storage/...`.
3. Tes login CMS, login member, upload, dan pembayaran QRIS. Callback YODU harus mengarah ke `https://api.koleksi.club`.
4. Biarkan Laravel Cloud & Vercel hidup 1–2 hari sebagai rollback, lalu matikan auto-deploy keduanya.

## Operasional

```bash
cd ~/koleksi/be/deploy
docker compose ps
docker compose logs -f app worker scheduler fe
docker compose exec app php artisan tinker
docker compose restart worker
sudo tail -f /usr/local/apps/apache2/logs/error_log      # error reverse proxy (502/503 = container mati)
```

## Tanpa Webuzo (Caddy)

Di VPS yang port 80/443-nya bebas (KVM / IP dedicated, tanpa panel), HTTPS cukup lewat Caddy di Docker.
Tambahkan di `deploy/.env`:

```
COMPOSE_FILE=docker-compose.yml:docker-compose.caddy.yml
ACME_EMAIL=admin@koleksi.club
```

[`Caddyfile`](Caddyfile) mengurus sertifikat Let's Encrypt, redirect `www`, dan header keamanan. Folder `webuzo/` tidak dipakai.

## Scaling

1. Pindah ke VPS KVM (cgroup v2, IP dedicated): clone repo, restore backup, pakai mode Caddy di atas.
2. Pindahkan MySQL ke managed DB atau VPS terpisah: hapus service `mysql`, lalu arahkan `DB_HOST`.
3. Pindahkan upload ke S3/R2 (isi `BACKEND_STORAGE_URL`), supaya `app` bisa dijalankan di lebih dari satu server.
