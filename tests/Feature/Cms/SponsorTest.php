<?php

use App\Enums\ContentStatus;
use App\Models\Sponsor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(Sponsor::DISK);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

function sponsorPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'PT Mobil Listrik Indonesia',
        'logo' => UploadedFile::fake()->image('logo.png', 400, 200),
        'url' => 'https://mli.co.id',
        'sort' => 1,
        'status' => 'published',
    ], $overrides);
}

test('guest cannot access sponsors', function () {
    $this->get(route('sponsors.index'))->assertRedirect(route('signin'));
});

test('index lists sponsors by sort and supports search & status filter', function () {
    Sponsor::factory()->published()->create(['title' => 'Sponsor Kedua', 'sort' => 2]);
    Sponsor::factory()->create(['title' => 'Sponsor Pertama', 'sort' => 1, 'url' => 'https://pertama.test']);

    $this->actingAs($this->admin)->get(route('sponsors.index'))
        ->assertOk()->assertSeeInOrder(['Sponsor Pertama', 'Sponsor Kedua']);

    $this->actingAs($this->admin)->get(route('sponsors.index', ['search' => 'pertama.test']))
        ->assertOk()->assertSee('Sponsor Pertama')->assertDontSee('Sponsor Kedua');

    $this->actingAs($this->admin)->get(route('sponsors.index', ['status' => 'published']))
        ->assertOk()->assertSee('Sponsor Kedua')->assertDontSee('Sponsor Pertama');
});

test('super admin can create a sponsor', function () {
    $this->actingAs($this->admin)->get(route('sponsors.create'))->assertOk();

    $this->actingAs($this->admin)->post(route('sponsors.store'), sponsorPayload())
        ->assertRedirect(route('sponsors.index'))->assertSessionHas('success');

    $sponsor = Sponsor::sole();

    expect($sponsor->title)->toBe('PT Mobil Listrik Indonesia')
        ->and($sponsor->url)->toBe('https://mli.co.id')
        ->and($sponsor->sort)->toBe(1)
        ->and($sponsor->status)->toBe(ContentStatus::Published)
        ->and($sponsor->logo)->toStartWith(Sponsor::DIRECTORY.'/'.now()->format('Y/m').'/')
        ->and($sponsor->created_by)->toBe($this->admin->id)
        ->and($sponsor->toArray())->toHaveKey('logo_url');

    Storage::disk(Sponsor::DISK)->assertExists($sponsor->logo);
});

test('url without scheme gets https and empty sort goes last', function () {
    Sponsor::factory()->create(['sort' => 7]);

    $this->actingAs($this->admin)->post(route('sponsors.store'), sponsorPayload(['url' => 'koleksi.id', 'sort' => '']))
        ->assertSessionHasNoErrors();

    expect(Sponsor::latest('id')->first())
        ->url->toBe('https://koleksi.id')
        ->sort->toBe(8);
});

test('url is optional', function () {
    $this->actingAs($this->admin)->post(route('sponsors.store'), sponsorPayload(['url' => '']))
        ->assertSessionHasNoErrors();

    expect(Sponsor::sole()->url)->toBeNull();
});

test('update keeps the logo when none is uploaded and replaces it otherwise', function () {
    $this->actingAs($this->admin)->post(route('sponsors.store'), sponsorPayload());
    $sponsor = Sponsor::sole();
    $first = $sponsor->logo;

    $this->actingAs($this->admin)->get(route('sponsors.edit', $sponsor))->assertOk()->assertSee('PT Mobil Listrik Indonesia');

    $this->actingAs($this->admin)->put(route('sponsors.update', $sponsor), sponsorPayload([
        'logo' => null, 'title' => 'Nama Baru', 'sort' => '',
    ]))->assertSessionHasNoErrors();

    expect($sponsor->fresh())
        ->logo->toBe($first)
        ->title->toBe('Nama Baru')
        ->sort->toBe(1) // urutan kosong saat edit = urutan lama dipertahankan
        ->updated_by->toBe($this->admin->id);

    $this->actingAs($this->admin)->put(route('sponsors.update', $sponsor), sponsorPayload([
        'logo' => UploadedFile::fake()->image('baru.webp', 400, 200),
    ]))->assertSessionHasNoErrors();

    $second = $sponsor->fresh()->logo;
    expect($second)->not->toBe($first);
    Storage::disk(Sponsor::DISK)->assertMissing($first);
    Storage::disk(Sponsor::DISK)->assertExists($second);
});

test('validation rejects invalid input', function (Closure $overrides, string $errorField) {
    $this->actingAs($this->admin)->post(route('sponsors.store'), sponsorPayload($overrides()))
        ->assertSessionHasErrors($errorField);

    expect(Sponsor::count())->toBe(0);
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

test('delete removes the sponsor and its logo', function () {
    $this->actingAs($this->admin)->post(route('sponsors.store'), sponsorPayload());
    $sponsor = Sponsor::sole();

    $this->actingAs($this->admin)->delete(route('sponsors.destroy', $sponsor))
        ->assertRedirect(route('sponsors.index'));

    expect(Sponsor::count())->toBe(0);
    Storage::disk(Sponsor::DISK)->assertMissing($sponsor->logo);
});

test('published scope with ordering returns sponsors for the website', function () {
    Sponsor::factory()->published()->create(['title' => 'B', 'sort' => 2]);
    Sponsor::factory()->published()->create(['title' => 'A', 'sort' => 1]);
    Sponsor::factory()->create(['title' => 'Draft', 'sort' => 0]);

    expect(Sponsor::published()->ordered()->pluck('title')->all())->toBe(['A', 'B']);
});

test('policy follows sponsors.* permissions for non super admin roles', function () {
    $editor = User::factory()->create();
    $sponsor = Sponsor::factory()->create();

    expect($editor->can('viewAny', Sponsor::class))->toBeFalse();

    $editor->givePermissionTo('sponsors.view', 'sponsors.update');

    expect($editor->can('viewAny', Sponsor::class))->toBeTrue()
        ->and($editor->can('update', $sponsor))->toBeTrue()
        ->and($editor->can('create', Sponsor::class))->toBeFalse()
        ->and($editor->can('delete', $sponsor))->toBeFalse();
});
