<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class SuperAdminSeeder extends Seeder
{
    /**
     * Seed the super admin account from SUPERADMIN_* env values (via config/superadmin.php).
     */
    public function run(): void
    {
        $email = config('superadmin.email');
        $password = config('superadmin.password');

        if (blank($email) || blank($password)) {
            throw new RuntimeException('Set SUPERADMIN_EMAIL dan SUPERADMIN_PASSWORD di .env sebelum menjalankan seeder.');
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->name = config('superadmin.name');

        // Password hanya diisi saat akun baru dibuat, supaya re-seed tidak menimpa password yang sudah diganti
        if (! $user->exists) {
            $user->password = $password;
            $user->email_verified_at = now();
        }

        $user->save();
        $user->assignRole('super-admin');
    }
}
