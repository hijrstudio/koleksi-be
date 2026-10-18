<?php

use App\Models\Member;
use App\Models\MemberVehicle;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(VehicleBrand::DISK);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

function memberVehicleFor(VehicleBrand $brand, ?VehicleModel $model = null): MemberVehicle
{
    return MemberVehicle::create([
        'member_id' => Member::factory()->create()->id,
        'vin' => strtoupper(fake()->unique()->bothify('#################')),
        'plat_kode' => 'B',
        'plat_nomor' => (string) fake()->unique()->numberBetween(1, 9999),
        'plat_status' => 'permanen',
        'vehicle_brand_id' => $brand->id,
        'vehicle_model_id' => $model?->id,
    ]);
}

function modelPayload(VehicleBrand $brand, array $overrides = []): array
{
    return array_merge([
        'vehicle_brand_id' => $brand->id,
        'name' => 'Ioniq 5',
        'is_active' => '1',
        'battery_kwh' => '72,6',
        'ac_kw' => '11',
        'dc_kw' => '220',
        'range_km' => '451',
    ], $overrides);
}

// ---------------------------------------------------------------- akses

test('guest and users without permission cannot manage the catalog', function () {
    $this->get(route('vehicle-brands.index'))->assertRedirect(route('signin'));
    $this->get(route('vehicle-models.index'))->assertRedirect(route('signin'));

    $user = User::factory()->create();
    $this->actingAs($user)->get(route('vehicle-brands.index'))->assertForbidden();
    $this->actingAs($user)->get(route('vehicle-models.index'))->assertForbidden();
    $this->actingAs($user)->post(route('vehicle-brands.store'), ['name' => 'X', 'is_active' => '1'])->assertForbidden();

    expect(VehicleBrand::count())->toBe(0);
});

// ---------------------------------------------------------------- merek

test('brand can be created with logo and gets a slug', function () {
    $this->actingAs($this->admin)->get(route('vehicle-brands.create'))->assertOk();

    $this->actingAs($this->admin)->post(route('vehicle-brands.store'), [
        'name' => '  Mercedes-Benz ',
        'logo' => UploadedFile::fake()->image('logo.png', 400, 200),
        'sort_order' => '',
        'is_active' => '1',
    ])->assertRedirect(route('vehicle-brands.index'))->assertSessionHasNoErrors();

    $brand = VehicleBrand::sole();

    expect($brand)
        ->name->toBe('Mercedes-Benz')
        ->slug->toBe('mercedes-benz')
        ->is_active->toBeTrue()
        ->created_by->toBe($this->admin->id);

    Storage::disk(VehicleBrand::DISK)->assertExists($brand->logo_path);
    $this->actingAs($this->admin)->get(route('vehicle-brands.index'))->assertOk()->assertSee('Mercedes-Benz');
});

test('brand validation rejects duplicates, lookalike slugs and svg logos', function (Closure $payload, string $field) {
    VehicleBrand::factory()->create(['name' => 'Mercedes-Benz']);

    $this->actingAs($this->admin)->post(route('vehicle-brands.store'), array_merge(['is_active' => '1'], $payload()))
        ->assertSessionHasErrors($field);

    expect(VehicleBrand::count())->toBe(1);
})->with([
    'duplicate name' => [fn () => ['name' => 'Mercedes-Benz'], 'name'],
    'lookalike slug' => [fn () => ['name' => 'Mercedes Benz'], 'name'],
    'symbols only' => [fn () => ['name' => '!!!'], 'name'],
    'svg logo' => [fn () => ['name' => 'BYD', 'logo' => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')], 'logo'],
]);

test('brand logo can be replaced and removed', function () {
    $this->actingAs($this->admin)->post(route('vehicle-brands.store'), [
        'name' => 'BYD', 'logo' => UploadedFile::fake()->image('a.png'), 'is_active' => '1',
    ]);
    $brand = VehicleBrand::sole();
    $first = $brand->logo_path;

    $this->actingAs($this->admin)->put(route('vehicle-brands.update', $brand), [
        'name' => 'BYD', 'logo' => UploadedFile::fake()->image('b.webp'), 'is_active' => '1',
    ])->assertSessionHasNoErrors();
    Storage::disk(VehicleBrand::DISK)->assertMissing($first);

    $this->actingAs($this->admin)->put(route('vehicle-brands.update', $brand), [
        'name' => 'BYD', 'remove_logo' => '1', 'is_active' => '0',
    ])->assertSessionHasNoErrors();

    expect($brand->fresh())->logo_path->toBeNull()->is_active->toBeFalse();
});

test('brand used by a member vehicle cannot be deleted', function () {
    $brand = VehicleBrand::factory()->create();
    memberVehicleFor($brand);

    $this->actingAs($this->admin)->delete(route('vehicle-brands.destroy', $brand))->assertSessionHas('error');
    expect($brand->fresh())->not->toBeNull();

    $unused = VehicleBrand::factory()->has(VehicleModel::factory()->count(2), 'models')->create();
    $this->actingAs($this->admin)->delete(route('vehicle-brands.destroy', $unused))->assertSessionHas('success');
    expect(VehicleBrand::find($unused->id))->toBeNull()
        ->and(VehicleModel::where('vehicle_brand_id', $unused->id)->count())->toBe(0);
});

// ---------------------------------------------------------------- tipe

test('model can be created with specs (comma decimals accepted)', function () {
    $brand = VehicleBrand::factory()->create(['name' => 'Hyundai']);

    $this->actingAs($this->admin)->get(route('vehicle-models.create', ['brand' => $brand->id]))->assertOk();

    $this->actingAs($this->admin)->post(route('vehicle-models.store'), modelPayload($brand))
        ->assertRedirect(route('vehicle-models.index'))->assertSessionHasNoErrors();

    $model = VehicleModel::sole();

    expect($model)
        ->name->toBe('Ioniq 5')
        ->battery_kwh->toBe('72.6')
        ->ac_kw->toBe('11.0')
        ->dc_kw->toBe('220.0')
        ->range_km->toBe(451)
        ->created_by->toBe($this->admin->id)
        ->and($model->hasSpecs())->toBeTrue();

    $this->actingAs($this->admin)->get(route('vehicle-models.index', ['brand' => $brand->id, 'specs' => 'complete']))
        ->assertOk()->assertSee('Ioniq 5');
});

test('model without any spec is allowed (dropdown only) and dc is optional', function () {
    $brand = VehicleBrand::factory()->create();

    $this->actingAs($this->admin)->post(route('vehicle-models.store'), modelPayload($brand, [
        'name' => 'Tanpa spek', 'battery_kwh' => '', 'ac_kw' => '', 'dc_kw' => '', 'range_km' => '',
    ]))->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->post(route('vehicle-models.store'), modelPayload($brand, [
        'name' => 'Tanpa DC', 'dc_kw' => '',
    ]))->assertSessionHasNoErrors();

    expect(VehicleModel::where('name', 'Tanpa spek')->sole()->hasSpecs())->toBeFalse()
        ->and(VehicleModel::where('name', 'Tanpa DC')->sole()->dc_kw)->toBeNull();
});

test('model validation rejects invalid input', function (Closure $overrides, string $field) {
    $brand = VehicleBrand::factory()->create();
    VehicleModel::factory()->create(['vehicle_brand_id' => $brand->id, 'name' => 'Ioniq 5']);

    $this->actingAs($this->admin)->post(route('vehicle-models.store'), modelPayload($brand, array_merge(['name' => 'Baru'], $overrides())))
        ->assertSessionHasErrors($field);

    expect(VehicleModel::count())->toBe(1);
})->with([
    'duplicate name in brand' => [fn () => ['name' => 'Ioniq 5'], 'name'],
    'unknown brand' => [fn () => ['vehicle_brand_id' => 999], 'vehicle_brand_id'],
    'partial specs' => [fn () => ['battery_kwh' => '', 'ac_kw' => ''], 'battery_kwh'],
    'dc without base specs' => [fn () => ['battery_kwh' => '', 'ac_kw' => '', 'range_km' => ''], 'range_km'],
    'two decimals' => [fn () => ['battery_kwh' => '72.65'], 'battery_kwh'],
    'negative' => [fn () => ['ac_kw' => '-1'], 'ac_kw'],
    'too large' => [fn () => ['range_km' => '99999'], 'range_km'],
    'not numeric' => [fn () => ['dc_kw' => 'abc'], 'dc_kw'],
]);

test('model used by a member keeps its brand and cannot be deleted', function () {
    $brand = VehicleBrand::factory()->create();
    $other = VehicleBrand::factory()->create();
    $model = VehicleModel::factory()->create(['vehicle_brand_id' => $brand->id]);
    memberVehicleFor($brand, $model);

    $this->actingAs($this->admin)->get(route('vehicle-models.edit', $model))->assertOk()->assertSee('sudah dipakai kendaraan member');

    $this->actingAs($this->admin)->put(route('vehicle-models.update', $model), modelPayload($other, ['name' => $model->name]))
        ->assertSessionHasErrors('vehicle_brand_id');

    $this->actingAs($this->admin)->put(route('vehicle-models.update', $model), modelPayload($brand, ['name' => $model->name, 'is_active' => '0']))
        ->assertSessionHasNoErrors();
    expect($model->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->admin)->delete(route('vehicle-models.destroy', $model))->assertSessionHas('error');
    expect($model->fresh())->not->toBeNull();
});
