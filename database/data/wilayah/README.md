# Data wilayah Indonesia

Kode & nama wilayah administrasi sesuai **Kepmendagri No 300.2.2-2430 Tahun 2025**, dari
[cahyadsn/wilayah](https://github.com/cahyadsn/wilayah) (`db/wilayah.sql`, lisensi MIT), dipecah per level:

| File            | Level             | Contoh kode     | Jumlah |
| --------------- | ----------------- | --------------- | -----: |
| provinces.csv   | Provinsi          | `31`            |     38 |
| regencies.csv   | Kabupaten / Kota  | `31.74`         |    514 |
| districts.csv   | Kecamatan         | `31.74.01`      |  7.285 |
| villages.csv    | Desa / Kelurahan  | `31.74.01.1001` | 83.762 |

Kode induk = prefix kode anak (`31.74.01.1001` → kecamatan `31.74.01` → kab/kota `31.74` → provinsi `31`).

Import / update (idempotent, aman dijalankan ulang): `php artisan db:seed --class=RegionSeeder`

Saat ada Kepmendagri baru: unduh ulang `db/wilayah.sql`, pecah lagi ke 4 CSV ini (kolom `code,name`), lalu jalankan seeder.
Wilayah yang dihapus di data baru tidak ikut terhapus dari DB (bisa masih dipakai member).
