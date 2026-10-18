<?php

use App\Enums\ContentStatus;
use App\Models\Partner;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(Partner::DISK);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

function partnerPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'PT Mobil Listrik Indonesia',
        'logo' => UploadedFile::fake()->image('logo.png', 400, 200),
        'url' => 'https://mli.co.id',
        'sort' => 1,
        'status' => 'published',
    ], $overrides);
}

test('guest cannot access partners', function () {
    $this->get(route('partners.index'))->assertRedirect(route('signin'));
});

test('index lists partners by sort and supports search & status filter', function () {
    Partner::factory()->published()->create(['title' => 'Partner Kedua', 'sort' => 2]);
    Partner::factory()->create(['title' => 'Partner Pertama', 'sort' => 1, 'url' => 'https://pertama.test']);

    $this->actingAs($this->admin)->get(route('partners.index'))
        ->assertOk()->assertSeeInOrder(['Partner Pertama', 'Partner Kedua']);

    $this->actingAs($this->admin)->get(route('partners.index', ['search' => 'pertama.test']))
        ->assertOk()->assertSee('Partner Pertama')->assertDontSee('Partner Kedua');

    $this->actingAs($this->admin)->get(route('partners.index', ['status' => 'published']))
        ->assertOk()->assertSee('Partner Kedua')->assertDontSee('Partner Pertama');
});

test('super admin can create a partner', function () {
    $this->actingAs($this->admin)->get(route('partners.create'))->assertOk();

    $this->actingAs($this->admin)->post(route('partners.store'), partnerPayload())
        ->assertRedirect(route('partners.index'))->assertSessionHas('success');

    $partner = Partner::sole();

    expect($partner->title)->toBe('PT Mobil Listrik Indonesia')
        ->and($partner->url)->toBe('https://mli.co.id')
        ->and($partner->sort)->toBe(1)
        ->and($partner->status)->toBe(ContentStatus::Published)
        ->and($partner->logo)->toStartWith(Partner::DIRECTORY.'/'.now()->format('Y/m').'/')
        ->and($partner->created_by)->toBe($this->admin->id)
        ->and($partner->toArray())->toHaveKey('logo_url');

    Storage::disk(Partner::DISK)->assertExists($partner->logo);
});

test('url without scheme gets https and empty sort goes last', function () {
    Partner::factory()->create(['sort' => 7]);

    $this->actingAs($this->admin)->post(route('partners.store'), partnerPayload(['url' => 'koleksi.id', 'sort' => '']))
        ->assertSessionHasNoErrors();

    expect(Partner::latest('id')->first())
        ->url->toBe('https://koleksi.id')
        ->sort->toBe(8);
});

test('url is optional', function () {
    $this->actingAs($this->admin)->post(route('partners.store'), partnerPayload(['url' => '']))
        ->assertSessionHasNoErrors();

    expect(Partner::sole()->url)->toBeNull();
});

test('update keeps the logo when none is uploaded and replaces it otherwise', function () {
    $this->actingAs($this->admin)->post(route('partners.store'), partnerPayload());
    $partner = Partner::sole();
    $first = $partner->logo;

    $this->actingAs($this->admin)->get(route('partners.edit', $partner))->assertOk()->assertSee('PT Mobil Listrik Indonesia');

    $this->actingAs($this->admin)->put(route('partners.update', $partner), partnerPayload([
        'logo' => null, 'title' => 'Nama Baru', 'sort' => '',
    ]))->assertSessionHasNoErrors();

    expect($partner->fresh())
        ->logo->toBe($first)
        ->title->toBe('Nama Baru')
        ->sort->toBe(1) // urutan kosong saat edit = urutan lama dipertahankan
        ->updated_by->toBe($this->admin->id);

    $this->actingAs($this->admin)->put(route('partners.update', $partner), partnerPayload([
        'logo' => UploadedFile::fake()->image('baru.webp', 400, 200),
    ]))->assertSessionHasNoErrors();

    $second = $partner->fresh()->logo;
    expect($second)->not->toBe($first);
    Storage::disk(Partner::DISK)->assertMissing($first);
    Storage::disk(Partner::DISK)->assertExists($second);
});

test('validation rejects invalid input', function (Closure $overrides, string $errorField) {
    $this->actingAs($this->admin)->post(route('partners.store'), partnerPayload($overrides()))
        ->assertSessionHasErrors($errorField);

    expect(Partner::count())->toBe(0);
})->with([
    'missing title' => [fn () => ['title' => ''], 'title'],
    'title too long' => [fn () => ['title' => str_repeat('a', 151)], 'title'],
    'missing logo on create' => [fn () => ['logo' => null], 'logo'],
    'svg logo' => [fn () => ['logo' => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')], 'logo'],
    'php disguised as png' => [fn () => ['logo' => UploadedFile::fake()->create('shell.png', 5, 'text/x-php')], 'logo'],
    'logo too large' => [fn () => ['logo' => UploadedFile::fake()->image('big.png')->size(3 * 1024)], 'logo'],
    'javascript url' => [fn () => ['url' => 'javascript:alert(1)'], 'url'],
    'ftp url' => [fn () => ['url' => 'ftp://files.test'], 'url'],
    'negative sort' => [fn () => ['sort' => -1], 'sort'],
    'invalid status' => [fn () => ['status' => 'archived'], 'status'],
]);

test('delete removes the partner and its logo', function () {
    $this->actingAs($this->admin)->post(route('partners.store'), partnerPayload());
    $partner = Partner::sole();

    $this->actingAs($this->admin)->delete(route('partners.destroy', $partner))
        ->assertRedirect(route('partners.index'));

    expect(Partner::count())->toBe(0);
    Storage::disk(Partner::DISK)->assertMissing($partner->logo);
});

test('published scope with ordering returns partners for the website', function () {
    Partner::factory()->published()->create(['title' => 'B', 'sort' => 2]);
    Partner::factory()->published()->create(['title' => 'A', 'sort' => 1]);
    Partner::factory()->create(['title' => 'Draft', 'sort' => 0]);

    expect(Partner::published()->ordered()->pluck('title')->all())->toBe(['A', 'B']);
});

test('policy follows partners.* permissions for non super admin roles', function () {
    $editor = User::factory()->create();
    $partner = Partner::factory()->create();

    expect($editor->can('viewAny', Partner::class))->toBeFalse();

    $editor->givePermissionTo('partners.view', 'partners.update');

    expect($editor->can('viewAny', Partner::class))->toBeTrue()
        ->and($editor->can('update', $partner))->toBeTrue()
        ->and($editor->can('create', Partner::class))->toBeFalse()
        ->and($editor->can('delete', $partner))->toBeFalse();
});
