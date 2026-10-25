<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Keanggotaan — jalan tiap hari.
| Production: cron `* * * * * php /path/artisan schedule:run`; lokal: `php artisan schedule:work` (ikut `composer dev`).
*/
Schedule::command('members:refresh-statuses')
    ->dailyAt('00:10')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('members:send-renewal-reminders')
    ->dailyAt('08:00')   // jam kirim yang wajar untuk member
    ->withoutOverlapping()
    ->onOneServer();

/*
| Pendaftar yang tidak memverifikasi e-mail — hapus beserta dokumennya.
*/
Schedule::command('members:prune-registrations')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->onOneServer();

/*
| Gambar konten rich editor (artikel panduan, berita) — hapus yang tidak dipakai (upload batal / dihapus dari konten).
*/
Schedule::command('content:prune-images')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
| Galeri foto — hapus foto hasil upload yang tidak jadi disimpan ke album.
*/
Schedule::command('gallery:prune-uploads')
    ->dailyAt('02:15')
    ->withoutOverlapping()
    ->onOneServer();

/*
| Token login (member & CMS) yang sudah kadaluarsa lebih dari 24 jam — hapus dari personal_access_tokens.
*/
Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
| Token "lupa kata sandi" member yang sudah kedaluwarsa — hapus dari member_password_reset_tokens.
*/
Schedule::command('auth:clear-resets members')
    ->dailyAt('03:05')
    ->withoutOverlapping()
    ->onOneServer();

/*
| Pembayaran iuran QRIS — jaring pengaman kalau callback gateway tidak sampai & member menutup halaman.
*/
Schedule::command('payments:reconcile')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
