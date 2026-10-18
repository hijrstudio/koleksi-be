<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class SuperAdminSeeder extends Seeder
{
    /**
     * Seed the super admin account from SUPERADMIN_* env values.
     */
    public function run(): void
    {
        $email = env('SUPERADMIN_EMAIL');
        $password = env('SUPERADMIN_PASSWORD');

        if (blank($email) || blank($password)) {
            throw new RuntimeException('Set SUPERADMIN_EMAIL dan SUPERADMIN_PASSWORD di .env sebelum menjalankan seeder.');
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->name = env('SUPERADMIN_NAME', 'Super Admin');

        // Password hanya diisi saat akun baru dibuat, supaya re-seed tidak menimpa password yang sudah diganti
        if (! $user->exists) {
            $user->password = $password;
            $user->email_verified_at = now();
        }

        $user->save();
        $user->assignRole('super-admin');
    }
}
