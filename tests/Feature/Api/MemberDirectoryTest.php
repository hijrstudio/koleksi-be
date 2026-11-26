<?php

use App\Models\Member;
use App\Models\MemberVehicle;
use App\Models\VehicleBrand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const DIRECTORY = '/api/v1/member/forum/members';

beforeEach(function () {
    Cache::flush();
    DB::table('provinces')->insert(['code' => '31', 'name' => 'DKI JAKARTA']);
    DB::table('regencies')->insert([
        ['code' => '31.74', 'province_code' => '31', 'name' => 'Kota Jakarta Selatan'],
        ['code' => '31.71', 'province_code' => '31', 'name' => 'Kota Jakarta Pusat'],
        ['code' => '31.72', 'province_code' => '31', 'name' => 'Kota Jakarta Utara'],
    ]);

    $this->viewer = Member::factory()->create(['no_lambung' => 1, 'nama_lengkap' => 'Viewer Satu', 'city_code' => '31.74']);
    $this->budi = Member::factory()->create([
        'no_lambung' => 7, 'nama_lengkap' => 'Budi Santoso', 'nama_panggilan' => 'Budi',
        'email' => 'budi@example.test', 'no_hp' => '081234567890', 'alamat' => 'Jl. Rahasia 1', 'city_code' => '31.74',
    ]);
    $this->sari = Member::factory()->create(['no_lambung' => 12, 'nama_lengkap' => 'Sari Dewi', 'city_code' => '31.71']);

    $brand = VehicleBrand::factory()->create(['name' => 'Hyundai']);
    $vehicle = ['plat_kode' => 'B', 'plat_nomor' => '1234', 'plat_seri' => 'XYZ', 'plat_status' => 'permanen'];
    MemberVehicle::create($vehicle + ['member_id' => $this->budi->id, 'brand_other' => 'Wuling', 'is_primary' => false]);
    MemberVehicle::create(['member_id' => $this->budi->id, 'vehicle_brand_id' => $brand->id, 'is_primary' => true, 'plat_nomor' => '5678'] + $vehicle);
    MemberVehicle::create(['member_id' => $this->sari->id, 'brand_other' => 'Polytron', 'plat_nomor' => '9999'] + $vehicle);
});

test('the directory is only for logged-in members', function () {
    $this->getJson(DIRECTORY)->assertUnauthorized();
    $this->getJson(DIRECTORY.'/cities')->assertUnauthorized();
});

test('it lists official members with only public fields', function () {
    // Pendaftar yang belum membayar & member yang dihapus tidak ikut
    Member::factory()->create(['no_lambung' => 20])->forceFill(['registration_status' => Member::REG_AWAITING_PAYMENT])->save();
    Member::factory()->create(['no_lambung' => 21])->delete();
    Sanctum::actingAs($this->viewer, ['member']);

    $res = $this->getJson(DIRECTORY)->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.1', [
            'no_lambung' => '#007',
            'name' => 'Budi',
            'avatar' => $this->budi->photo_url,
            'vehicle_brand' => 'Hyundai',   // kendaraan utama
            'city' => 'Kota Jakarta Selatan',
        ])
        ->assertJsonPath('data.2.vehicle_brand', 'Polytron');

    // Data pribadi tidak pernah ikut
    expect($res->getContent())->not->toContain('budi@example.test', '081234567890', 'Rahasia', '5678', '"id"');
});

test('it searches name or number only and filters by city', function () {
    Sanctum::actingAs($this->viewer, ['member']);

    $this->getJson(DIRECTORY.'?search=santoso')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.no_lambung', '#007');
    $this->getJson(DIRECTORY.'?search=%237')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Budi');
    // Email & No. HP tidak bisa dipakai mencari
    $this->getJson(DIRECTORY.'?search=budi@example.test')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(DIRECTORY.'?search=0812345')->assertOk()->assertJsonCount(0, 'data');

    $this->getJson(DIRECTORY.'?city=31.71')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Sari Dewi');
    $this->getJson(DIRECTORY.'?city=evil')->assertUnprocessable();
});

test('the city filter lists each city members filled in, once', function () {
    Sanctum::actingAs($this->viewer, ['member']);

    $this->getJson(DIRECTORY.'/cities')->assertOk()->assertExactJson(['data' => [
        ['code' => '31.71', 'name' => 'Kota Jakarta Pusat'],
        ['code' => '31.74', 'name' => 'Kota Jakarta Selatan'],
    ]]);
});
