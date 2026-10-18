<?php

use App\Models\Member;
use App\Models\MemberVehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Merek/tipe yang dinonaktifkan di CMS (EV Garage) tetap boleh dipertahankan member yang sudah memakainya,
 * tapi tidak bisa dipilih baru.
 */
beforeEach(function () {
    $this->brand = VehicleBrand::factory()->create(['name' => 'Lama']);
    $this->model = VehicleModel::factory()->create(['vehicle_brand_id' => $this->brand->id, 'name' => 'Tipe Lama']);

    $this->member = Member::factory()->create();
    MemberVehicle::create([
        'member_id' => $this->member->id,
        'vin' => 'ABCDEFGHJK1234567',
        'plat_kode' => 'B',
        'plat_nomor' => '1234',
        'plat_status' => 'permanen',
        'vehicle_brand_id' => $this->brand->id,
        'vehicle_model_id' => $this->model->id,
    ]);

    $this->brand->update(['is_active' => false]);
    $this->model->update(['is_active' => false]);

    // Dibuat sebelum login member: TracksAuthor mengisi created_by dari user yang login
    $this->otherBrand = VehicleBrand::factory()->inactive()->create();
    $this->otherModel = VehicleModel::factory()->inactive()->create(['vehicle_brand_id' => $this->brand->id]);

    Sanctum::actingAs($this->member);
});

function vehiclePayload(array $overrides = []): array
{
    return array_merge(['has_vehicle' => '1'], $overrides);
}

test('profile returns the current brand & model names even when inactive', function () {
    $this->getJson(route('api.v1.member.profile.show'))
        ->assertOk()
        ->assertJsonPath('data.vehicle.vehicle_brand.name', 'Lama')
        ->assertJsonPath('data.vehicle.vehicle_brand.is_active', false)
        ->assertJsonPath('data.vehicle.vehicle_model.name', 'Tipe Lama');
});

test('member may keep an inactive brand & model', function () {
    $this->postJson(route('api.v1.member.profile.update'), vehiclePayload([
        'vehicle_brand_id' => $this->brand->id,
        'vehicle_model_id' => $this->model->id,
    ]))->assertJsonMissingValidationErrors(['vehicle_brand_id', 'vehicle_model_id']);
});

test('member cannot pick another inactive brand or model', function () {
    $this->postJson(route('api.v1.member.profile.update'), vehiclePayload([
        'vehicle_brand_id' => $this->otherBrand->id,
    ]))->assertJsonValidationErrors('vehicle_brand_id');

    $this->postJson(route('api.v1.member.profile.update'), vehiclePayload([
        'vehicle_brand_id' => $this->brand->id,
        'vehicle_model_id' => $this->otherModel->id,
    ]))->assertJsonValidationErrors('vehicle_model_id');
});
