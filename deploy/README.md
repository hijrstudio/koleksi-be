# Deploy VPS (FE + BE)

Server produksi: **VPS Hosting Jagoan Hosting** (OpenVZ, NAT, panel Webuzo). Webuzo tidak boleh dimatikan,
jadi Apache & MariaDB bawaan Webuzo tetap dipakai, dan aplikasi jalan di 2 container Docker:

```
Internet ─► Domain Forward Jagoan (IP 101.50.1.14) ─► Apache Webuzo :80/:443 (SSL Let's Encrypt Webuzo)
              ├─ koleksi.club, www  ─► 127.0.0.1:3000 ─► fe   (Next.js)
              ├─ api.koleksi.club   ─► 127.0.0.1:8080 ─► app  (Laravel: hanya /api, /storage, /up)
              └─ cms.koleksi.club   ─► 127.0.0.1:8080 ─► app  (Laravel: hanya CMS admin)
            app = FrankenPHP + queue worker + scheduler (supervisord)  ─► MariaDB Webuzo 127.0.0.1:3306
            Image dibangun GitHub Actions ─► ghcr.io/hijrstudio/koleksi-{be,fe} ─► di-pull VPS
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

### ⚠️ Batas thread OpenVZ

VPS ini membatasi **jumlah thread untuk seluruh VPS** (termasuk layanan Webuzo). Saat diukur, dengan Webuzo +
MySQL + Redis berjalan hanya tersisa ±72 thread; stack awal (5 container) melampauinya dan membuat server tidak
bisa `fork` sama sekali (`pthread_create failed: Resource temporarily unavailable`). Karena itu:

| Keputusan | Alasan |
|---|---|
| Hanya 2 container (`app`, `fe`), worker & scheduler di dalam `app` | tiap container menambah ±10 thread (containerd-shim) |
| `network_mode: host`, listen di `127.0.0.1` | tanpa `docker-proxy` per port |
| MariaDB Webuzo, cache/queue/session `database`, tanpa Redis | MariaDB Webuzo sudah jalan; MySQL container ±40 thread |
| `PHP_THREADS=4`, `GOMAXPROCS=2`, `UV_THREADPOOL_SIZE=2` | default FrankenPHP/Node mengikuti 12 CPU |
| `pids_limit` per container | container bermasalah tidak bisa menghabiskan thread seluruh VPS |
| Image dibangun di GitHub Actions | build (BuildKit, worker Next.js per CPU) di VPS memakan ratusan thread |

**Jangan** menjalankan `docker compose build` di VPS. Untuk mengukur sisa thread:

```bash
python3 -c "import threading as t;e=t.Event();n=0
try:
  while n<1000: t.Thread(target=e.wait,daemon=True).start();n+=1
except RuntimeError: pass
print('sisa thread:',n)"
```

Kalau server sampai tidak bisa menjalankan `docker` (`pthread_create failed`), hentikan container lewat API
Docker dengan `curl` (single-thread):

```bash
for c in koleksi-app-1 koleksi-fe-1; do
  curl -s --unix-socket /var/run/docker.sock -X POST -H Content-Type:application/json \
    -d '{"RestartPolicy":{"Name":"no"}}' http://localhost/containers/$c/update
  curl -s --unix-socket /var/run/docker.sock -X POST "http://localhost/containers/$c/stop?t=10"
done
```

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
| A | `www` | `101.50.1.14` | |
| A | `mail` | `152.44.45.11` | |
| MX | `@` | `mx.zoho.com` | 10 |
| MX | `@` | `mx2.zoho.com` | 20 |
| MX | `@` | `mx3.zoho.com` | 50 |
| TXT | `@` | `v=spf1 include:zohomail.com ~all` | |
| TXT | `@` | `zoho-verification=zb13674199.zmverify.zoho.com` | |
| TXT | `@` | `brevo-code:899f12e0cb8ef8444b4eb51301ad738e` (Brevo) | |
| CNAME | `brevo1._domainkey` | `b1.koleksi-club.dkim.brevo.com` (DKIM Brevo) | |
| CNAME | `brevo2._domainkey` | `b2.koleksi-club.dkim.brevo.com` (DKIM Brevo) | |
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:rua@dmarc.brevo.com` | |

`www` **harus A record**, bukan CNAME: Automatic SSL Webuzo melewatkan `www` yang berupa CNAME, sehingga
sertifikat tanpa `www` dan `https://www.koleksi.club` error `ERR_CERT_COMMON_NAME_INVALID`.

**Begitu transfer selesai:** buat ulang semua record di atas di DNS baru, **lalu** ganti nameserver.
Wix menghapus zona DNS domain yang pindah, jadi website **dan email Zoho** mati kalau ini dilewati.

## 2. Domain Forward Jagoan (sudah)

Member area → VPS → Domain Forward: HTTP (80) & HTTPS (443) untuk `koleksi.club`, `www.koleksi.club`,
`api.koleksi.club`, `cms.koleksi.club`. Port 2002–2005 adalah panel Webuzo, dikunci provider (tidak bisa dihapus).

## 3. Webuzo: domain, SSL & database

Buka panel lewat SSH tunnel dari laptop (port panel tidak dibuka di firewall):

```powershell
ssh -L 2005:127.0.0.1:2005 -L 2003:127.0.0.1:2003 koleksi
```

Biarkan terminal itu terbuka, lalu di browser (abaikan peringatan sertifikat):

1. **Admin panel** `https://localhost:2005`, login `root` + password root VPS.
   Tambah user `koleksi` dengan domain utama `koleksi.club`.
2. **User panel** `https://localhost:2003`, login user tadi.
   Tambah subdomain `api.koleksi.club` dan `cms.koleksi.club`.
3. Pasang SSL **Let's Encrypt** lewat **SSL → Automatic SSL** (bukan *Install Certificate*, itu untuk tempel manual)
   untuk `koleksi.club`, `api.koleksi.club`, dan `cms.koleksi.club`
   ([panduan Jagoan](https://www.jagoanhosting.com/tutorial/vps-hosting/cara-install-ssl-domain-pada-layanan-vps-hosting)).

   **Wajib sebelumnya**, agar `www` ikut masuk sertifikat:
   ```bash
   echo "127.0.0.1 koleksi.club www.koleksi.club" | sudo tee -a /etc/hosts
   ```
   Sebelum meminta sertifikat, Webuzo menguji tiap alias dengan membuka `http://<alias>/.well-known/...` **dari
   VPS sendiri**. VPS NAT tidak bisa menjangkau IP publiknya sendiri, jadi tanpa baris di atas tes timeout dan
   alias dibuang (log: `Failed to verify alias : www.koleksi.club`, sertifikat hanya `koleksi.club`).
   `/etc/hosts` permanen (selamat dari restart), jadi perpanjangan otomatis tetap menyertakan `www`.
   Juga pastikan `www` berupa **A record** di DNS publik dan di zona DNS Webuzo (Domain → DNS Zone).
   `Failed to verify alias : mail.koleksi.club` itu normal (DNS `mail` ke server lain).

   Mengganti sertifikat: centang domain → **Revoke** → **Install** → `sudo systemctl restart httpd`.
   Setelah *Revoke*, domain memakai sertifikat self-signed Webuzo (website error) sampai *Install* selesai.
   Perpanjangan otomatis oleh cron Webuzo (`/etc/cron.d/lets_encrypt`), jangan dihapus.
4. **Database** (user panel → Databases): buat database + user MariaDB, beri user itu **semua hak akses** ke
   database tersebut. Webuzo menambahkan prefix nama user panel (mis. `koleksi_web`). Catat nama database,
   user & password untuk `deploy/.env`. Container terhubung lewat `127.0.0.1:3306` (network host).

Cek dari laptop: `https://koleksi.club`, `https://api.koleksi.club` & `https://cms.koleksi.club` harus tampil dengan gembok valid
(isi halaman masih default Webuzo, itu normal).

## 4. Clone & isi env

```bash
mkdir -p ~/koleksi && cd ~/koleksi
git clone https://github.com/hijrstudio/koleksi-be.git be
git clone https://github.com/hijrstudio/koleksi-fe.git fe

cd ~/koleksi/be/deploy && cp .env.example .env
sed -i "s/^BACKEND_API_TOKEN=.*/BACKEND_API_TOKEN=$(openssl rand -hex 32)/" .env
nano .env          # isi DB_DATABASE, DB_USERNAME, DB_PASSWORD dari langkah 3.4
chmod 600 .env
```

`be/.env` (env Laravel, `chmod 600`): isi seperti `.env.sample.deploy`, dengan catatan:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY=` baru (`openssl rand -base64 32`, diawali `base64:`).
- Log: `LOG_CHANNEL=stack`, `LOG_STACK=daily`, `LOG_DAILY_DAYS=14`, `LOG_LEVEL=warning` (disk hanya 40 GB).
- **Tidak perlu diisi** (otomatis dari `deploy/.env` oleh `docker-compose.yml`): `APP_URL`, `FRONTEND_URL`,
  `FRONTEND_API_TOKEN`, `DB_*`, `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER`.
- `SUPERADMIN_PASSWORD` **baru** (password lama tertulis di test repo public). Seeder hanya memakainya saat akun
  dibuat; kalau DB dipindah dari Laravel Cloud, ganti password superadmin lewat CMS.
- `YODU_*`: sandbox dulu; ganti ke kredensial live (`YODU_BASE_URL=https://yodupay-api.yodu.id`, key lewat
  `YODU_PRIVATE_KEY` base64) setelah tes pembayaran di server lolos. Jangan commit key.

### E-mail aplikasi (Brevo)

VPS Hosting **memblokir SMTP keluar** (port 25/465/587, termasuk Gmail & Zoho; dikonfirmasi CS Jagoan), jadi
e-mail aplikasi (verifikasi pendaftaran, approval, pengingat perpanjangan, ganti password) dikirim lewat
**Brevo** (gratis 300 e-mail/hari) di port **2525** yang terbuka. Mailbox manusia (`@koleksi.club`) tetap di Zoho.

`be/.env`:
```
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=2525
MAIL_USERNAME=<login SMTP Brevo, xxxx@smtp-brevo.com>
MAIL_PASSWORD=<SMTP key Brevo>
MAIL_FROM_ADDRESS=noreply@koleksi.club
MAIL_FROM_NAME="KOLEKSI"
```

- **SMTP key `koleksi-vps-production` kedaluwarsa 25 Okt 2027** (juga setelah 90 hari tidak dipakai). Buat key baru
  sebelum tanggal itu (Brevo → Settings → SMTP & API), isi `MAIL_PASSWORD`, lalu
  `docker compose up -d --no-deps --force-recreate app`. Key kedaluwarsa = e-mail berhenti tanpa peringatan.
- Domain `koleksi.club` diautentikasi di Brevo lewat record `brevo-code`, DKIM `brevo1/2._domainkey`, dan `_dmarc`
  (lihat tabel DNS). SPF Zoho tidak diubah. Saat pindah DNS, record ini **wajib ikut dibuat ulang**.
- Ganti ke SMTP mailbox domain sendiri hanya bisa kalau port-nya tidak diblokir dari VPS (cek dulu dengan
  `curl -v telnet://<host>:<port>`); cukup ganti `MAIL_*`, tanpa ubah kode.
- E-mail yang gagal (mis. saat SMTP bermasalah) tersimpan di `failed_jobs`: kirim ulang dengan
  `docker compose exec app php artisan queue:retry all`.

## 5. GitHub: image & secrets

Image dibangun oleh workflow `.github/workflows/deploy-vps.yml` di **kedua** repo setiap push ke `main`
(job `image`), lalu di-deploy ke VPS (job `deploy`). Di masing-masing repo → Settings → Secrets and variables → Actions:

| | koleksi-be | koleksi-fe |
|---|---|---|
| Secret `BACKEND_API_TOKEN` | – | sama dengan `deploy/.env` di server (dipakai build) |
| Secrets `VPS_HOST` / `VPS_PORT` / `VPS_USER` / `VPS_SSH_KEY` | `101.50.1.14` / `50012` / `deploy` / private key CI | sama |
| Variable `VPS_DEPLOY` | `true` setelah deploy pertama berhasil | sama |

`VPS_SSH_KEY`: buat key khusus CI (`ssh-keygen -t ed25519 -f koleksi-ci`), public key-nya ditambahkan ke
`~deploy/.ssh/authorized_keys`.

Setelah build pertama, package `koleksi-be` & `koleksi-fe` muncul di GitHub (profil → Packages). Repo public, jadi
jadikan package **public** (Package settings → Change visibility) supaya VPS bisa `docker pull` tanpa login.
Image tidak berisi secret (`.env` tidak ikut, token FE lewat build secret). Kalau package dibuat private:
`docker login ghcr.io -u <user> -p <PAT read:packages>` di VPS.

## 6. Deploy pertama

Urutan penting: build `fe` di GitHub memanggil `https://api.koleksi.club` (prerender ISR), jadi backend + SSL +
proxy Apache harus online sebelum workflow koleksi-fe dijalankan.

```bash
cd ~/koleksi/be/deploy
set -a; . ./.env; set +a

# Backend (image dari workflow koleksi-be)
docker compose pull app
docker compose run --rm -T app php artisan migrate --force </dev/null
docker compose run --rm -T app php artisan db:seed --force </dev/null   # hanya DB baru
docker compose up -d app </dev/null
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/up        # harus 200

# Reverse proxy Apache Webuzo -> container
sudo mkdir -p /var/webuzo-data/apache2/custom/domains
sudo cp ~/koleksi/be/deploy/webuzo/*.conf /var/webuzo-data/apache2/custom/domains/
sudo /usr/local/apps/apache2/bin/apachectl -t && sudo systemctl restart httpd
curl -s -o /dev/null -w '%{http_code}\n' https://api.koleksi.club/up       # harus 200
curl -s -o /dev/null -w '%{http_code}\n' https://cms.koleksi.club/signin   # harus 200 (login CMS)

# Frontend: jalankan workflow koleksi-fe (Actions → Build & Deploy VPS → Run workflow), lalu
docker compose pull fe && docker compose up -d fe </dev/null
curl -s -o /dev/null -w '%{http_code}\n' https://koleksi.club/            # harus 200
```

Setelah itu set `VPS_DEPLOY=true` di kedua repo: push ke `main` = build image + `deploy.sh` otomatis.
Manual: `bash ~/koleksi/be/deploy/deploy.sh`. Rollback: isi `IMAGE_TAG=<sha commit>` di `deploy/.env`, jalankan `deploy.sh`.
Kalau isi `deploy/webuzo/*.conf` berubah, salin ulang ke `/var/webuzo-data/apache2/custom/domains/` + restart `httpd`.

## 7. Backup

```bash
crontab -e
30 2 * * * bash ~/koleksi/be/deploy/backup.sh >> ~/backups/backup.log 2>&1
```

Disk hanya 40 GB: salin `~/backups` ke luar VPS (rclone ke R2/S3/Google Drive), dan uji restore minimal sekali:

```bash
gunzip -c db_XXXX.sql.gz | mariadb -h127.0.0.1 -u"$DB_USERNAME" -p "$DB_DATABASE"
docker compose exec -T app tar -xzf - -C /app/storage < storage_XXXX.tar.gz
```

## 8. Migrasi dari Laravel Cloud + Vercel

1. Export DB dari Laravel Cloud, lalu import ke MariaDB Webuzo (perintah restore di atas).
2. Salin isi bucket Laravel Cloud ke `storage/app/public`, misalnya `rclone copy <bucket> ./public`, lalu
   `docker compose cp ./public app:/app/storage/app/` dan `docker compose exec -u root app chown -R app:app /app/storage`.
   URL file berubah ke `https://api.koleksi.club/storage/...`.
3. Tes login CMS, login member, upload, dan pembayaran QRIS. Callback YODU harus mengarah ke `https://api.koleksi.club`.
4. Biarkan Laravel Cloud & Vercel hidup 1–2 hari sebagai rollback, lalu matikan auto-deploy keduanya.

## Operasional

```bash
cd ~/koleksi/be/deploy
docker compose ps
docker compose logs -f app fe
docker compose exec app supervisorctl -c /app/docker/supervisord.conf status    # web / queue / scheduler
docker compose exec app supervisorctl -c /app/docker/supervisord.conf restart queue
docker compose exec -e XDG_CONFIG_HOME=/tmp app php artisan tinker
docker stats --no-stream                                                     # kolom PIDS = thread per container
sudo tail -f /usr/local/apps/apache2/logs/error_log      # error reverse proxy (502/503 = container mati)
```

### Pemutihan tunggakan iuran

Kebijakan pengurus (Okt 2026): tunggakan sebelum periode 2026-2027 diputihkan; yang ditagih mulai periode 2026-2027
tiap member (`MEMBERSHIP_ARREARS_CUTOFF_YEAR`, default 2026). Penagihan sudah mengikuti aturan ini otomatis; perintah
di bawah **mencatatnya resmi** di database dan menghitung ulang status member. Riwayat Iuran di CMS hanya menampilkan
periode mulai 2026-2027, jadi periode yang diputihkan tidak terlihat.
Aman dijalankan ulang. Jalankan lagi setelah import Excel yang menambah tagihan lama.

```bash
docker compose exec app php artisan members:waive-arrears --dry-run   # lihat dampaknya dulu
docker compose exec app php artisan members:waive-arrears             # jalankan
```

Member yang sedang punya QRIS pending dilewati (QR-nya masih memuat nominal lama) — jalankan ulang ±15 menit kemudian.

## Tes di laptop (localhost)

Image yang sama dengan produksi, dibuka lewat `localhost` (tanpa domain, Apache, atau HTTPS).
Memakai [`docker-compose.local.yml`](docker-compose.local.yml) + [`local.env`](local.env) (nilai dummy) dan
`be/.env` lokal. MariaDB ikut jalan sebagai container.

```powershell
cd D:\PROJECT_DEV\koleksi\be\deploy
$env:BACKEND_API_TOKEN = "local-dev-token"     # sama dengan local.env, dipakai build fe
$dc = "docker compose -p koleksi-local --env-file local.env -f docker-compose.yml -f docker-compose.local.yml"

iex "$dc build app"
iex "$dc up -d db"
iex "$dc run --rm -T app php artisan migrate --seed --force"   # sekali, saat DB masih kosong
iex "$dc up -d app"
iex "$dc build fe"                                              # butuh app sudah jalan (prerender)
iex "$dc up -d fe"
```

| | URL |
|---|---|
| Website | http://localhost:3000 |
| CMS | http://localhost:8080 (login `SUPERADMIN_*` dari `be/.env` lokal) |
| API | http://localhost:8080/api/v1 (header `Authorization: Bearer local-dev-token`) |

Selesai: `iex "$dc down"` (tambah `-v` untuk menghapus DB tes).

## Tanpa Webuzo (Caddy)

Di VPS yang port 80/443-nya bebas (KVM / IP dedicated, tanpa panel), HTTPS cukup lewat Caddy di Docker.
Siapkan MariaDB/MySQL sendiri (atau managed DB), lalu tambahkan di `deploy/.env`:

```
COMPOSE_FILE=docker-compose.yml:docker-compose.caddy.yml
CMS_DOMAIN=cms.koleksi.club
ACME_EMAIL=admin@koleksi.club
```

[`Caddyfile`](Caddyfile) mengurus sertifikat Let's Encrypt, redirect `www`, pemisahan api/cms yang sama, dan header
keamanan. Folder `webuzo/` tidak dipakai.

## Scaling

1. Pindah ke VPS KVM (cgroup v2, IP dedicated, tanpa batas thread ketat): clone repo, restore backup, mode Caddy di atas.
   Batas `PHP_THREADS` / `pids_limit` bisa dinaikkan.
2. Pisahkan worker/scheduler ke container sendiri: jalankan image `app` kedua dengan `RUN_QUEUE`/`RUN_SCHEDULER`,
   dan set `RUN_QUEUE=false RUN_SCHEDULER=false` di `app` web.
3. Pindahkan DB ke managed DB, dan upload ke S3/R2 (isi `BACKEND_STORAGE_URL`), supaya `app` bisa dijalankan di
   lebih dari satu server.
