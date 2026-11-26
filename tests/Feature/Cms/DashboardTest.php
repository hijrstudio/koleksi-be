<?php

use App\Models\Member;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

test('the dashboard shows active and inactive members and the latest transactions', function () {
    Member::factory()->count(3)->active()->create();
    $budi = Member::factory()->create(['no_lambung' => 77, 'nama_lengkap' => 'Budi Dashboard']);
    Member::factory()->create()->forceFill(['registration_status' => Member::REG_AWAITING_PAYMENT, 'no_lambung' => null])->save();

    Payment::create([
        'member_id' => $budi->id, 'gateway' => 'fake', 'purpose' => Payment::PURPOSE_REGISTRATION,
        'merchant_ref' => 'KLKR-DASH-1', 'amount' => 500000, 'status' => Payment::SUCCESS, 'paid_at' => now(),
    ]);
    Payment::create([
        'member_id' => $budi->id, 'gateway' => 'fake', 'merchant_ref' => 'KLK-DASH-EXPIRED',
        'amount' => 300000, 'status' => Payment::EXPIRED,
    ]);

    $res = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
        ->assertSee('Anggota aktif')
        ->assertSee('Anggota tidak aktif')
        ->assertSee('Pendaftar menunggu bayar')
        ->assertSee('Transaksi terbaru')
        ->assertSee('KLKR-DASH-1')
        ->assertSee('QRIS Pendaftaran')
        ->assertSee('Budi Dashboard')
        ->assertSee('Rp 500.000')
        ->assertDontSee('KLK-DASH-EXPIRED');   // hanya yang lunas

    // 3 aktif, 1 tidak aktif (pendaftar yang belum bayar tidak dihitung sebagai anggota)
    expect($res->viewData('members'))->toBe(['active' => 3, 'inactive' => 1, 'awaiting' => 1]);
});

test('the dashboard works on an empty system', function () {
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
        ->assertSee('Belum ada transaksi lunas.')
        ->assertSee('Rp 0');
});
