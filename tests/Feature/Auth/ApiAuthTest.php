<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->superAdmin = User::factory()->create([
        'email' => 'superadmin@koleksi.club',
        'password' => 'P4ssw0rd123!',
    ]);
    $this->superAdmin->assignRole('super-admin');
});

test('super admin gets a sanctum token and can use it', function () {
    $token = $this->postJson('/api/auth/login', [
        'email' => 'superadmin@koleksi.club',
        'password' => 'P4ssw0rd123!',
    ])->assertOk()
        ->assertJsonPath('user.email', 'superadmin@koleksi.club')
        ->assertJsonPath('user.roles', ['super-admin'])
        ->json('token');

    $this->withToken($token)->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'superadmin@koleksi.club');
});

test('api login with wrong password returns 422', function () {
    $this->postJson('/api/auth/login', [
        'email' => 'superadmin@koleksi.club',
        'password' => 'salah',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

test('api returns 401 without a token', function () {
    $this->get('/api/auth/me')->assertUnauthorized();
});

test('api logout revokes the token', function () {
    $token = $this->superAdmin->createToken('test')->plainTextToken;

    $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

    expect($this->superAdmin->tokens()->count())->toBe(0);
});
