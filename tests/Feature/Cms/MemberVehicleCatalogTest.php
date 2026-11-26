<?php

use App\Models\Member;
use App\Models\MemberVehicle;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
    $this->wuling = VehicleBrand::factory()->create(['name' => 'Wuling']);
});

function writtenVehicle(array $attributes): MemberVehicle
{
    static $plate = 1000;

    return MemberVehicle::create($attributes + [
        'member_id' => Member::factory()->create()->id,
        'plat_kode' => 'B', 'plat_nomor' => (string) $plate++, 'plat_status' => 'permanen',
    ]);
}

test('written brands and models are grouped case-insensitively with their count', function () {
    writtenVehicle(['brand_other' => 'Jaecoo', 'model_other' => 'J7']);
    writtenVehicle(['brand_other' => 'JAECOO', 'model_other' => 'j7']);
    writtenVehicle(['vehicle_brand_id' => $this->wuling->id, 'model_other' => 'Binguo']);
    writtenVehicle(['vehicle_brand_id' => $this->wuling->id, 'vehicle_model_id' => VehicleModel::factory()->create(['vehicle_brand_id' => $this->wuling->id])->id]);
    writtenVehicle(['brand_other' => 'Jaecoo', 'model_other' => 'J7'])->member->delete(); // member dihapus: tidak dihitung

    $this->actingAs($this->admin)->get(route('member-vehicles.uncatalogued'))
        ->assertOk()
        ->assertViewHas('groups', fn ($groups) => $groups->count() === 2
            && (int) $groups->first()->total === 2
            && strtolower($groups->first()->brand_other) === 'jaecoo')
        ->assertSee('Binguo');
});

test('linking a group fills the catalog brand and model and clears the written text', function () {
    $jaecoo = VehicleBrand::factory()->create(['name' => 'Jaecoo']);
    $j7 = VehicleModel::factory()->create(['vehicle_brand_id' => $jaecoo->id, 'name' => 'J7']);
    $a = writtenVehicle(['brand_other' => 'Jaecoo', 'model_other' => 'J7']);
    $b = writtenVehicle(['brand_other' => 'jaecoo', 'model_other' => 'J7 ']);
    $c = writtenVehicle(['brand_other' => 'Jaecoo', 'model_other' => 'J8']); // kelompok lain, tidak ikut

    $this->actingAs($this->admin)->put(route('member-vehicles.uncatalogued.link'), [
        'source_brand_other' => 'Jaecoo', 'source_model_other' => 'J7',
        'vehicle_brand_id' => $jaecoo->id, 'vehicle_model_id' => $j7->id,
    ])->assertRedirect(route('member-vehicles.uncatalogued'))->assertSessionHas('success', '2 kendaraan ditautkan ke katalog.');

    foreach ([$a, $b] as $vehicle) {
        $vehicle->refresh();
        expect($vehicle->vehicle_model_id)->toBe($j7->id)
            ->and($vehicle->brand_other)->toBeNull()
            ->and($vehicle->model_other)->toBeNull()
            ->and($vehicle->isUncatalogued())->toBeFalse();
    }
    expect($c->fresh()->model_other)->toBe('J8');
});

test('a catalog brand with a written model is linked within that brand only', function () {
    $binguo = VehicleModel::factory()->create(['vehicle_brand_id' => $this->wuling->id, 'name' => 'Binguo']);
    $vehicle = writtenVehicle(['vehicle_brand_id' => $this->wuling->id, 'model_other' => 'Binguo']);

    $this->actingAs($this->admin)->put(route('member-vehicles.uncatalogued.link'), [
        'source_brand_id' => $this->wuling->id, 'source_model_other' => 'binguo',
        'vehicle_brand_id' => $this->wuling->id, 'vehicle_model_id' => $binguo->id,
    ])->assertSessionHas('success');

    expect($vehicle->fresh()->vehicle_model_id)->toBe($binguo->id);
});

test('the target model must belong to the chosen brand, and linking needs members.update', function () {
    $other = VehicleModel::factory()->create();
    writtenVehicle(['brand_other' => 'Jaecoo', 'model_other' => 'J7']);

    $this->actingAs($this->admin)->put(route('member-vehicles.uncatalogued.link'), [
        'source_brand_other' => 'Jaecoo', 'source_model_other' => 'J7',
        'vehicle_brand_id' => $this->wuling->id, 'vehicle_model_id' => $other->id,
    ])->assertSessionHasErrors('vehicle_model_id');

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('members.view');
    expect($viewer->can('updateSettings', Member::class))->toBeFalse()
        ->and(MemberVehicle::query()->uncatalogued()->count())->toBe(1);
});

test('the member page flags a written vehicle and admin edits keep the written text', function () {
    $member = Member::factory()->create(['no_lambung' => 7, 'has_vehicle' => true, 'tgl_bergabung' => today()]);
    $member->vehicles()->create(['plat_kode' => 'B', 'plat_nomor' => '7', 'plat_status' => 'permanen', 'is_primary' => true,
        'brand_other' => 'Jaecoo', 'model_other' => 'J7']);

    $this->actingAs($this->admin)->get(route('members.show', $member))->assertSee('Jaecoo J7')->assertSee('di luar katalog');

    $this->actingAs($this->admin)->put(route('members.update', $member), [
        'no_lambung' => 7, 'nama_lengkap' => $member->nama_lengkap, 'email' => $member->email, 'no_hp' => $member->no_hp,
        'tgl_bergabung' => today()->toDateString(), 'password_mode' => 'keep', 'has_vehicle' => '1',
        'plat_kode' => 'B', 'plat_nomor' => '7', 'plat_status' => 'permanen', 'brand_other' => 'Jaecoo', 'model_other' => 'J7',
    ])->assertSessionHasNoErrors();

    expect($member->primaryVehicle()->displayName())->toBe('Jaecoo J7');
});
