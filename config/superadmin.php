<?php

return [

    /*
    | Akun super admin awal yang dibuat SuperAdminSeeder.
    | Dibaca lewat config (bukan env() langsung di seeder) supaya tetap terbaca saat config di-cache
    | (php artisan optimize di build Laravel Cloud).
    */
    'name' => env('SUPERADMIN_NAME', 'Super Admin'),
    'email' => env('SUPERADMIN_EMAIL'),
    'password' => env('SUPERADMIN_PASSWORD'),

];
