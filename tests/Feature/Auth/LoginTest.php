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

test('super admin can sign in and reach the dashboard', function () {
    $this->post(route('signin.store'), [
        'email' => 'superadmin@koleksi.club',
        'password' => 'P4ssw0rd123!',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($this->superAdmin);
    $this->get(route('dashboard'))->assertOk()->assertSee('superadmin@koleksi.club');
});

test('guest is redirected back to the intended page after sign in', function () {
    $this->get(route('profile'))->assertRedirect(route('signin'));

    $this->post(route('signin.store'), [
        'email' => 'superadmin@koleksi.club',
        'password' => 'P4ssw0rd123!',
    ])->assertRedirect(route('profile'));
});

test('wrong password is rejected', function () {
    $this->from(route('signin'))->post(route('signin.store'), [
        'email' => 'superadmin@koleksi.club',
        'password' => 'salah',
    ])->assertRedirect(route('signin'))->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('user without a CMS role cannot sign in', function () {
    User::factory()->create(['email' => 'biasa@koleksi.club', 'password' => 'P4ssw0rd123!']);

    $this->post(route('signin.store'), [
        'email' => 'biasa@koleksi.club',
        'password' => 'P4ssw0rd123!',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('sign in is rate limited after 5 failed attempts', function () {
    foreach (range(1, 5) as $i) {
        $this->post(route('signin.store'), ['email' => 'superadmin@koleksi.club', 'password' => 'salah']);
    }

    $this->post(route('signin.store'), [
        'email' => 'superadmin@koleksi.club',
        'password' => 'P4ssw0rd123!',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('signed in user visiting sign in page is sent to the dashboard', function () {
    $this->actingAs($this->superAdmin)->get(route('signin'))->assertRedirect(route('dashboard'));
});

test('super admin can sign out', function () {
    $this->actingAs($this->superAdmin)->post(route('logout'))->assertRedirect(route('signin'));

    $this->assertGuest();
});
