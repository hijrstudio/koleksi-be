<?php

use App\Enums\ContentStatus;
use App\Enums\ManagementGroup;
use App\Models\ManagementMember;
use App\Models\ManagementPeriod;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(ManagementMember::DISK);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

function managementPayload(array $overrides = []): array
{
    return array_merge([
        'start_year' => 2024,
        'end_year' => 2029,
        'status' => 'published',
        'members' => [
            'a1' => ['group' => 'penasihat', 'name' => 'T.A. Edison', 'position' => ['id' => 'Ketua Umum', 'en' => 'General Chairman'],
                'photo' => UploadedFile::fake()->image('edison.jpg', 400, 400)],
            'a2' => ['group' => 'penasihat', 'name' => 'N. Tesla', 'position' => ['id' => 'Wakil Ketua', 'en' => '']],
            'b1' => ['group' => 'bidang', 'name' => 'Nama Anda', 'position' => ['id' => 'Bidang Edukasi dan Literasi', 'en' => 'Education and Literacy']],
        ],
    ], $overrides);
}

test('guest cannot access management', function () {
    $this->get(route('management-periods.index'))->assertRedirect(route('signin'));
});

test('index lists periods with member counts and supports search & status filter', function () {
    $published = ManagementPeriod::factory()->published()->create(['start_year' => 2024, 'end_year' => 2029]);
    ManagementMember::factory()->penasihat()->count(2)->for($published, 'period')->create();
    ManagementPeriod::factory()->create(['start_year' => 2019, 'end_year' => 2024]);

    $this->actingAs($this->admin)->get(route('management-periods.index'))
        ->assertOk()->assertSee('2024–2029')->assertSee('2019–2024')->assertSee('2 orang');

    $this->actingAs($this->admin)->get(route('management-periods.index', ['search' => '2029']))
        ->assertOk()->assertSee('2024–2029')->assertDontSee('2019–2024');

    $this->actingAs($this->admin)->get(route('management-periods.index', ['status' => 'draft']))
        ->assertOk()->assertSee('2019–2024')->assertDontSee('2024–2029');
});

test('create form is prefilled with default core positions', function () {
    $this->actingAs($this->admin)->get(route('management-periods.create'))
        ->assertOk()
        ->assertSee('Ketua Umum')
        ->assertSee('Wakil Bendahara');
});

test('super admin can create a period with members in one form', function () {
    $this->actingAs($this->admin)->post(route('management-periods.store'), managementPayload())
        ->assertRedirect(route('management-periods.index'))
        ->assertSessionHas('success');

    $period = ManagementPeriod::sole();
    $members = $period->members;

    expect($period->label())->toBe('2024–2029')
        ->and($period->status)->toBe(ContentStatus::Published)
        ->and($period->created_by)->toBe($this->admin->id)
        ->and($members->pluck('name')->all())->toBe(['T.A. Edison', 'N. Tesla', 'Nama Anda'])
        ->and($members->pluck('sort_order')->all())->toBe([1, 2, 1])
        ->and($members[0]->group)->toBe(ManagementGroup::Penasihat)
        ->and($members[1]->position)->toBe(['id' => 'Wakil Ketua', 'en' => null])
        ->and($members[1]->positionFor('en'))->toBe('Wakil Ketua');

    Storage::disk(ManagementMember::DISK)->assertExists($members[0]->photo);
});

test('update syncs members: updates, reorders, adds and deletes', function () {
    $this->actingAs($this->admin)->post(route('management-periods.store'), managementPayload());
    $period = ManagementPeriod::sole();
    [$edison, $tesla, $bidang] = $period->members->all();
    $oldPhoto = $edison->photo;

    $this->actingAs($this->admin)->put(route('management-periods.update', $period), managementPayload([
        'members' => [
            // Tesla dipindah ke atas, Edison ganti foto, bidang lama dihapus, bidang baru ditambah
            "m{$tesla->id}" => ['id' => $tesla->id, 'group' => 'penasihat', 'name' => 'N. Tesla', 'position' => ['id' => 'Ketua Umum', 'en' => '']],
            "m{$edison->id}" => ['id' => $edison->id, 'group' => 'penasihat', 'name' => 'Edison', 'position' => ['id' => 'Wakil Ketua', 'en' => ''],
                'photo' => UploadedFile::fake()->image('new.png', 300, 300)],
            'new1' => ['group' => 'bidang', 'name' => 'Elon M.', 'position' => ['id' => 'Koordinator Wilayah', 'en' => 'Regional Coordinator']],
        ],
    ]))->assertRedirect(route('management-periods.index'))->assertSessionHasNoErrors();

    $members = $period->fresh()->members;

    expect($members->pluck('name')->all())->toBe(['N. Tesla', 'Edison', 'Elon M.'])
        ->and($members->pluck('sort_order')->all())->toBe([1, 2, 1])
        ->and($members[0]->id)->toBe($tesla->id)
        ->and(ManagementMember::find($bidang->id))->toBeNull();

    Storage::disk(ManagementMember::DISK)->assertMissing($oldPhoto);
    Storage::disk(ManagementMember::DISK)->assertExists($members[1]->photo);
});

test('member photo can be removed', function () {
    $this->actingAs($this->admin)->post(route('management-periods.store'), managementPayload());
    $period = ManagementPeriod::sole();
    $edison = $period->members->first();

    $this->actingAs($this->admin)->put(route('management-periods.update', $period), managementPayload([
        'members' => [
            "m{$edison->id}" => ['id' => $edison->id, 'group' => 'penasihat', 'name' => 'Edison', 'position' => ['id' => 'Ketua Umum'], 'remove_photo' => '1'],
        ],
    ]))->assertSessionHasNoErrors();

    expect($edison->fresh()->photo)->toBeNull();
    Storage::disk(ManagementMember::DISK)->assertMissing($edison->photo);
});

test('cannot hijack a member that belongs to another period', function () {
    $other = ManagementMember::factory()->create(['name' => 'Milik periode lain']);
    $period = ManagementPeriod::factory()->create(['start_year' => 2030, 'end_year' => 2035]);

    $this->actingAs($this->admin)->put(route('management-periods.update', $period), managementPayload([
        'start_year' => 2030,
        'end_year' => 2035,
        'members' => [
            'x' => ['id' => $other->id, 'group' => 'bidang', 'name' => 'Diubah', 'position' => ['id' => 'Bidang']],
        ],
    ]))->assertSessionHasErrors('members.x.id');

    expect($other->fresh()->name)->toBe('Milik periode lain');
});

test('validation rejects invalid input', function (array $overrides, string $errorField) {
    ManagementPeriod::factory()->create(['start_year' => 2019, 'end_year' => 2024]);

    $this->actingAs($this->admin)->post(route('management-periods.store'), managementPayload($overrides))
        ->assertSessionHasErrors($errorField);
})->with([
    'end before start' => [['start_year' => 2029, 'end_year' => 2024], 'end_year'],
    'duplicate period' => [['start_year' => 2019, 'end_year' => 2024], 'end_year'],
    'no members' => [['members' => []], 'members'],
    'bad group' => [['members' => ['a' => ['group' => 'ketua', 'name' => 'X', 'position' => ['id' => 'Y']]]], 'members.a.group'],
    'missing name' => [['members' => ['a' => ['group' => 'bidang', 'name' => '', 'position' => ['id' => 'Y']]]], 'members.a.name'],
    'missing position id' => [['members' => ['a' => ['group' => 'bidang', 'name' => 'X', 'position' => ['en' => 'Y']]]], 'members.a.position.id'],
    'id on create' => [['members' => ['a' => ['id' => 1, 'group' => 'bidang', 'name' => 'X', 'position' => ['id' => 'Y']]]], 'members.a.id'],
    'svg photo' => [['members' => ['a' => ['group' => 'bidang', 'name' => 'X', 'position' => ['id' => 'Y'],
        'photo' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')]]], 'members.a.photo'],
    'photo too large' => [['members' => ['a' => ['group' => 'bidang', 'name' => 'X', 'position' => ['id' => 'Y'],
        'photo' => UploadedFile::fake()->image('big.jpg')->size(3 * 1024)]]], 'members.a.photo'],
]);

test('edit form shows saved members', function () {
    $this->actingAs($this->admin)->post(route('management-periods.store'), managementPayload());

    $this->actingAs($this->admin)->get(route('management-periods.edit', ManagementPeriod::sole()))
        ->assertOk()->assertSee('T.A. Edison')->assertSee('Bidang Edukasi dan Literasi');
});

test('delete removes the period, its members and photos', function () {
    $this->actingAs($this->admin)->post(route('management-periods.store'), managementPayload());
    $period = ManagementPeriod::sole();
    $photo = $period->members->first()->photo;

    $this->actingAs($this->admin)->delete(route('management-periods.destroy', $period))
        ->assertRedirect(route('management-periods.index'));

    expect(ManagementPeriod::count())->toBe(0)->and(ManagementMember::count())->toBe(0);
    Storage::disk(ManagementMember::DISK)->assertMissing($photo);
});

test('policy follows management.* permissions for non super admin roles', function () {
    $editor = User::factory()->create();
    $period = ManagementPeriod::factory()->create();

    expect($editor->can('viewAny', ManagementPeriod::class))->toBeFalse();

    $editor->givePermissionTo('management.view', 'management.update');

    expect($editor->can('viewAny', ManagementPeriod::class))->toBeTrue()
        ->and($editor->can('update', $period))->toBeTrue()
        ->and($editor->can('create', ManagementPeriod::class))->toBeFalse()
        ->and($editor->can('delete', $period))->toBeFalse();
});
