<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('super admin is created from config values', function () {
    config(['superadmin.name' => 'Admin Staging', 'superadmin.email' => 'admin@example.com', 'superadmin.password' => 'rahasia-123']);

    $this->seed([RoleSeeder::class, SuperAdminSeeder::class]);

    $user = User::where('email', 'admin@example.com')->firstOrFail();
    expect($user->name)->toBe('Admin Staging')
        ->and($user->hasRole('super-admin'))->toBeTrue();
});

test('missing super admin credentials fail with a clear message', function () {
    config(['superadmin.email' => null]);

    $this->seed([RoleSeeder::class, SuperAdminSeeder::class]);
})->throws(RuntimeException::class, 'SUPERADMIN_EMAIL');
