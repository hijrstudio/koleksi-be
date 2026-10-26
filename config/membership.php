<?php

return [

    /*
    | Periode keanggotaan = 1 tahun dihitung dari tanggal bergabung member
    | (bergabung 10 Feb 2026 → 10 Feb 2026 – 9 Feb 2027, daftar ulang Feb 2027).
    |
    | Masa tenggang setelah periode berakhir: member tetap aktif sampai
    | akhir periode + grace_days, lalu otomatis tidak aktif kalau belum daftar ulang.
    */
    'grace_days' => (int) env('MEMBERSHIP_GRACE_DAYS', 30),

    /*
    | Nominal iuran tahunan (Rupiah) untuk tagihan baru.
    */
    'annual_fee' => (int) env('MEMBERSHIP_ANNUAL_FEE', 0),

    /*
    | Pemutihan tunggakan (keputusan pengurus, Okt 2026): periode keanggotaan yang dimulai SEBELUM tahun ini
    | tidak pernah ditagih lagi — yang ditagih mulai periode "2026-2027" tiap member. Tunggakan lama dicatat
    | resmi sebagai "dibebaskan (pemutihan)" lewat `php artisan members:waive-arrears`.
    | Kosongkan (MEMBERSHIP_ARREARS_CUTOFF_YEAR=) untuk menagih semua tunggakan lagi.
    */
    'arrears_cutoff_year' => env('MEMBERSHIP_ARREARS_CUTOFF_YEAR', 2026) ? (int) env('MEMBERSHIP_ARREARS_CUTOFF_YEAR', 2026) : null,

    /*
    | Pengingat daftar ulang dikirim H-x sebelum jatuh tempo (0 = pada hari jatuh tempo).
    | Kalau scheduler sempat tidak jalan, hanya tahap paling mendesak yang dikirim.
    */
    'reminder_days' => array_map('intval', explode(',', env('MEMBERSHIP_REMINDER_DAYS', '30,7,0'))),

    /*
    | Link daftar ulang di aplikasi member (FE), dipakai di email & notifikasi.
    */
    'renewal_url' => env('MEMBERSHIP_RENEWAL_URL', env('FRONTEND_URL', 'http://localhost:3000').'/daftar-ulang'),

    /*
    | Masa berlaku token login member (hari). Setelah lewat, member harus login ulang.
    | Token kadaluarsa dibersihkan harian oleh `sanctum:prune-expired` (routes/console.php).
    */
    'token_ttl_days' => (int) env('MEMBER_TOKEN_TTL_DAYS', 30),

    /*
    | Halaman FE "atur ulang kata sandi" — tombol di e-mail lupa kata sandi mengarah ke sini
    | (?token=…&email=…). Masa berlaku link: config/auth.php → passwords.members.expire.
    */
    'password_reset_url' => env('MEMBER_PASSWORD_RESET_URL', env('FRONTEND_URL', 'http://localhost:3000').'/lupa-password/atur-ulang'),

    /*
    | Pendaftaran member baru lewat website (form /daftar di FE):
    |   daftar → verifikasi e-mail → review pengurus di CMS → disetujui → bayar iuran pertama (QRIS) → aktif.
    */
    'registration' => [
        // Masa berlaku link verifikasi e-mail (menit)
        'verify_ttl_minutes' => (int) env('MEMBER_REGISTRATION_VERIFY_TTL', 1440),

        // Pendaftar yang tidak memverifikasi e-mail dihapus otomatis setelah N hari (members:prune-registrations)
        'unverified_ttl_days' => (int) env('MEMBER_REGISTRATION_UNVERIFIED_DAYS', 7),

        // Halaman FE tujuan setelah link verifikasi diklik (?status=verified|already|expired|invalid)
        'verified_redirect' => env('MEMBER_REGISTRATION_VERIFIED_URL', env('FRONTEND_URL', 'http://localhost:3000').'/daftar/verifikasi'),

        // Halaman login FE (tombol di e-mail persetujuan) — setelah login member diarahkan ke dasbor untuk bayar iuran
        'login_url' => env('MEMBER_LOGIN_URL', env('FRONTEND_URL', 'http://localhost:3000').'/masuk?next=%2Fdasbor'),

        // E-mail tambahan yang menerima pemberitahuan pendaftar baru (selain admin CMS), pisahkan dengan koma
        'admin_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('MEMBER_REGISTRATION_ADMIN_EMAILS', ''))))),
    ],
];
