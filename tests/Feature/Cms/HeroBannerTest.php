<?php

use App\Enums\ContentStatus;
use App\Enums\MediaType;
use App\Models\HeroBanner;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(HeroBanner::DISK);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

function validPayload(array $overrides = []): array
{
    return array_merge([
        'file' => UploadedFile::fake()->image('banner.jpg', 1920, 800),
        'title' => ['id' => 'Selamat datang', 'en' => 'Welcome'],
        'status' => 'published',
    ], $overrides);
}

test('guest cannot access hero banners', function () {
    $this->get(route('hero-banners.index'))->assertRedirect(route('signin'));
});

test('index lists banners and supports search & status filter', function () {
    HeroBanner::factory()->published()->create(['title' => ['id' => 'Promo Lebaran', 'en' => 'Eid Promo']]);
    HeroBanner::factory()->create(['title' => ['id' => 'Koleksi Baru', 'en' => 'New Collection']]);

    $this->actingAs($this->admin)->get(route('hero-banners.index'))
        ->assertOk()->assertSee('Promo Lebaran')->assertSee('Koleksi Baru');

    $this->actingAs($this->admin)->get(route('hero-banners.index', ['search' => 'Eid']))
        ->assertOk()->assertSee('Promo Lebaran')->assertDontSee('Koleksi Baru');

    $this->actingAs($this->admin)->get(route('hero-banners.index', ['status' => 'draft']))
        ->assertOk()->assertSee('Koleksi Baru')->assertDontSee('Promo Lebaran');
});

test('create form is displayed', function () {
    $this->actingAs($this->admin)->get(route('hero-banners.create'))->assertOk();
});

test('super admin can create an image banner', function () {
    $this->actingAs($this->admin)->post(route('hero-banners.store'), validPayload())
        ->assertRedirect(route('hero-banners.index'))
        ->assertSessionHas('success');

    $banner = HeroBanner::sole();
    expect($banner->title)->toBe(['id' => 'Selamat datang', 'en' => 'Welcome'])
        ->and($banner->status)->toBe(ContentStatus::Published)
        ->and($banner->file_type)->toBe(MediaType::Image)
        ->and($banner->created_by)->toBe($this->admin->id)
        ->and($banner->updated_by)->toBe($this->admin->id);

    Storage::disk(HeroBanner::DISK)->assertExists($banner->file_path);
});

test('super admin can create a video banner', function () {
    $video = UploadedFile::fake()->create('intro.mp4', 2048, 'video/mp4');

    $this->actingAs($this->admin)->post(route('hero-banners.store'), validPayload(['file' => $video]))
        ->assertRedirect(route('hero-banners.index'));

    expect(HeroBanner::sole()->file_type)->toBe(MediaType::Video);
});

test('validation rejects missing fields, bad status and disallowed files', function (array $overrides, string $errorField) {
    $this->actingAs($this->admin)->post(route('hero-banners.store'), validPayload($overrides))
        ->assertSessionHasErrors($errorField);

    expect(HeroBanner::count())->toBe(0);
})->with([
    'missing file' => [['file' => null], 'file'],
    'svg not allowed' => [['file' => UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml')], 'file'],
    'php disguised' => [['file' => UploadedFile::fake()->create('shell.php', 10, 'application/x-php')], 'file'],
    'image too large' => [['file' => UploadedFile::fake()->image('big.jpg')->size(6 * 1024)], 'file'],
    'missing title id' => [['title' => ['en' => 'Welcome']], 'title.id'],
    'missing title en' => [['title' => ['id' => 'Halo']], 'title.en'],
    'invalid status' => [['status' => 'archived'], 'status'],
]);

test('update without new file keeps the old file', function () {
    $banner = HeroBanner::factory()->create();
    Storage::disk(HeroBanner::DISK)->put($banner->file_path, 'old');

    $this->actingAs($this->admin)->put(route('hero-banners.update', $banner), [
        'title' => ['id' => 'Judul baru', 'en' => 'New title'],
        'status' => 'draft',
    ])->assertRedirect(route('hero-banners.index'));

    expect($banner->fresh()->title['id'])->toBe('Judul baru')
        ->and($banner->fresh()->updated_by)->toBe($this->admin->id);
    Storage::disk(HeroBanner::DISK)->assertExists($banner->file_path);
});

test('update with new file replaces and deletes the old file', function () {
    $banner = HeroBanner::factory()->create();
    Storage::disk(HeroBanner::DISK)->put($banner->file_path, 'old');
    $oldPath = $banner->file_path;

    $this->actingAs($this->admin)->put(route('hero-banners.update', $banner), validPayload())
        ->assertRedirect(route('hero-banners.index'));

    $banner->refresh();
    expect($banner->file_path)->not->toBe($oldPath);
    Storage::disk(HeroBanner::DISK)->assertMissing($oldPath);
    Storage::disk(HeroBanner::DISK)->assertExists($banner->file_path);
});

test('delete removes the record and its file', function () {
    $banner = HeroBanner::factory()->create();
    Storage::disk(HeroBanner::DISK)->put($banner->file_path, 'x');

    $this->actingAs($this->admin)->delete(route('hero-banners.destroy', $banner))
        ->assertRedirect(route('hero-banners.index'));

    expect(HeroBanner::count())->toBe(0);
    Storage::disk(HeroBanner::DISK)->assertMissing($banner->file_path);
});

test('user without a CMS role is forbidden', function () {
    $user = User::factory()->create();
    $banner = HeroBanner::factory()->create();

    $this->actingAs($user)->get(route('hero-banners.index'))->assertForbidden();
    $this->actingAs($user)->post(route('hero-banners.store'), validPayload())->assertForbidden();
    $this->actingAs($user)->delete(route('hero-banners.destroy', $banner))->assertForbidden();
    expect(HeroBanner::count())->toBe(1);
});

test('policy follows hero-banners.* permissions for non super admin roles', function () {
    $editor = User::factory()->create();
    $banner = HeroBanner::factory()->create();

    expect($editor->can('viewAny', HeroBanner::class))->toBeFalse()
        ->and($editor->can('delete', $banner))->toBeFalse();

    $editor->givePermissionTo('hero-banners.view', 'hero-banners.update');

    expect($editor->can('viewAny', HeroBanner::class))->toBeTrue()
        ->and($editor->can('update', $banner))->toBeTrue()
        ->and($editor->can('delete', $banner))->toBeFalse();
});
