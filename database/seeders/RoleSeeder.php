<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Permission per modul CMS. Tambahkan modul baru di sini.
     *
     * @var array<string, list<string>>
     */
    public const MODULE_PERMISSIONS = [
        'hero-banners' => ['view', 'create', 'update', 'delete'],
        'about-us' => ['view', 'update'], // single row: simpan pertama kali = update
        'management' => ['view', 'create', 'update', 'delete'],
        'faqs' => ['view', 'create', 'update', 'delete'],
        'guide-categories' => ['view', 'create', 'update', 'delete'],
        'guide-articles' => ['view', 'create', 'update', 'delete'],
        'guide-videos' => ['view', 'create', 'update', 'delete'],
        'guide-infographics' => ['view', 'create', 'update', 'delete'],
        'guide-playbooks' => ['view', 'create', 'update', 'delete'],
        'gallery-albums' => ['view', 'create', 'update', 'delete'],
        'news' => ['view', 'create', 'update', 'delete'],
        'event-categories' => ['view', 'create', 'update', 'delete'],
        'events' => ['view', 'create', 'update', 'delete'],
        'partners' => ['view', 'create', 'update', 'delete'],
        'sponsors' => ['view', 'create', 'update', 'delete'],
        'spklu-operators' => ['view', 'create', 'update', 'delete'],
        'vehicle-brands' => ['view', 'create', 'update', 'delete'],
        'vehicle-models' => ['view', 'create', 'update', 'delete'],
        'members' => ['view', 'update-status', 'review'], // review = setujui / tolak pendaftaran baru
    ];

    /**
     * Seed the application's roles & permissions.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::MODULE_PERMISSIONS as $module => $actions) {
            foreach ($actions as $action) {
                Permission::findOrCreate("{$module}.{$action}", 'web');
            }
        }

        // super-admin tidak perlu diberi permission satu per satu: di-bypass lewat Gate::before (AppServiceProvider)
        Role::findOrCreate('super-admin', 'web');
    }
}
