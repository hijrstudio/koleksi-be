<?php

use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
});

test('requires frontend token', function () {
    $this->getJson(route('api.v1.ev-garage.brands'))->assertUnauthorized();
    $this->getJson(route('api.v1.ev-garage.vehicles'))->assertUnauthorized();
});

test('brands only lists active brands that have a garage model, in CMS order, in one query', function () {
    $kia = VehicleBrand::factory()->create(['name' => 'Kia', 'sort_order' => 2]);
    $byd = VehicleBrand::factory()->create(['name' => 'BYD', 'sort_order' => 1]);
    $noSpecs = VehicleBrand::factory()->create(['name' => 'Tanpa Spek']);
    $inactive = VehicleBrand::factory()->inactive()->create(['name' => 'Nonaktif']);

    VehicleModel::factory()->withSpecs()->create(['vehicle_brand_id' => $kia->id]);
    VehicleModel::factory()->withSpecs()->create(['vehicle_brand_id' => $byd->id]);
    VehicleModel::factory()->create(['vehicle_brand_id' => $noSpecs->id]);
    VehicleModel::factory()->withSpecs()->inactive()->create(['vehicle_brand_id' => $noSpecs->id]);
    VehicleModel::factory()->withSpecs()->create(['vehicle_brand_id' => $inactive->id]);

    DB::enableQueryLog();

    $this->withToken('test-token')->getJson(route('api.v1.ev-garage.brands'))
        ->assertOk()
        ->assertJsonPath('data.*.name', ['BYD', 'Kia'])
        ->assertJsonPath('data.0.slug', 'byd')
        ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'logo']]]);

    expect(DB::getQueryLog())->toHaveCount(1);
});

test('vehicles lists garage models sorted by brand then type, 18 per page', function () {
    $byd = VehicleBrand::factory()->create(['name' => 'BYD']);
    $kia = VehicleBrand::factory()->create(['name' => 'Kia']);

    VehicleModel::factory()->withSpecs(['battery_kwh' => 77.4, 'ac_kw' => 11, 'dc_kw' => null, 'range_km' => 528])
        ->create(['vehicle_brand_id' => $kia->id, 'name' => 'EV6']);
    VehicleModel::factory()->withSpecs()->create(['vehicle_brand_id' => $byd->id, 'name' => 'Seal']);
    VehicleModel::factory()->withSpecs()->create(['vehicle_brand_id' => $byd->id, 'name' => 'Dolphin']);
    VehicleModel::factory()->create(['vehicle_brand_id' => $byd->id, 'name' => 'Tanpa Spek']);

    $this->withToken('test-token')->getJson(route('api.v1.ev-garage.vehicles'))
        ->assertOk()
        ->assertJsonPath('data.*.type', ['Dolphin', 'Seal', 'EV6'])
        ->assertJsonPath('data.2.brand.name', 'Kia')
        ->assertJsonPath('data.2.battery_kwh', 77.4)
        ->assertJsonPath('data.2.dc_kw', null)
        ->assertJsonPath('data.2.range_km', 528)
        ->assertJsonPath('meta.per_page', 18)
        ->assertJsonPath('meta.total', 3);

    $this->withToken('test-token')->getJson(route('api.v1.ev-garage.vehicles', ['sort' => 'desc']))
        ->assertOk()->assertJsonPath('data.*.type', ['EV6', 'Seal', 'Dolphin']);
});

test('vehicles can be filtered by brand slug and searched', function () {
    $byd = VehicleBrand::factory()->create(['name' => 'BYD']);
    $kia = VehicleBrand::factory()->create(['name' => 'Kia']);
    VehicleModel::factory()->withSpecs()->create(['vehicle_brand_id' => $byd->id, 'name' => 'Seal']);
    VehicleModel::factory()->withSpecs()->create(['vehicle_brand_id' => $kia->id, 'name' => 'EV9']);

    $this->withToken('test-token')->getJson(route('api.v1.ev-garage.vehicles', ['brand' => 'kia']))
        ->assertOk()->assertJsonPath('data.*.type', ['EV9']);

    $this->withToken('test-token')->getJson(route('api.v1.ev-garage.vehicles', ['search' => 'byd']))
        ->assertOk()->assertJsonPath('data.*.type', ['Seal']);

    $this->withToken('test-token')->getJson(route('api.v1.ev-garage.vehicles', ['search' => '_']))
        ->assertOk()->assertJsonCount(0, 'data');
});

test('vehicles rejects invalid query', function (array $query) {
    $this->withToken('test-token')->getJson(route('api.v1.ev-garage.vehicles', $query))->assertUnprocessable();
})->with([
    'brand with sql' => [['brand' => "kia' or 1=1"]],
    'sort' => [['sort' => 'rand']],
    'per page' => [['per_page' => 500]],
]);
