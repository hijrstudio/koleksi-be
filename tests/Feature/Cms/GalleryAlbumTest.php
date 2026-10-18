<?php

use App\Enums\ContentStatus;
use App\Models\GalleryAlbum;
use App\Models\GalleryPhoto;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(GalleryAlbum::DISK);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

/** Upload foto lewat endpoint AJAX, kembalikan path-nya */
function uploadGalleryPhoto(string $name = 'foto.jpg'): string
{
    return test()->actingAs(test()->admin)
        ->postJson(route('gallery-albums.photo-uploads.store'), ['image' => UploadedFile::fake()->image($name, 1600, 1200)])
        ->assertCreated()
        ->json('path');
}

function albumPayload(array $overrides = []): array
{
    return array_merge([
        'title' => ['id' => 'Touring Bali 2026', 'en' => 'Bali Touring 2026'],
        'gallery_date' => '2026-09-20',
        'status' => 'published',
        'image' => UploadedFile::fake()->image('cover.jpg', 1200, 900),
        'photos' => [['path' => uploadGalleryPhoto('a.jpg')], ['path' => uploadGalleryPhoto('b.png')]],
    ], $overrides);
}

test('guest cannot access gallery albums', function () {
    $this->get(route('gallery-albums.index'))->assertRedirect(route('signin'));
});

test('index lists albums with photo count and supports search & status filter', function () {
    $album = GalleryAlbum::factory()->published()->create(['title' => ['id' => 'Kopdar Jakarta', 'en' => 'Jakarta Meetup']]);
    GalleryPhoto::factory()->count(3)->for($album, 'album')->create();
    GalleryAlbum::factory()->create(['title' => ['id' => 'Baksos Bandung', 'en' => 'Bandung Charity']]);

    $this->actingAs($this->admin)->get(route('gallery-albums.index'))
        ->assertOk()->assertSee('Kopdar Jakarta')->assertSee('Baksos Bandung')->assertSee('3 foto');

    $this->actingAs($this->admin)->get(route('gallery-albums.index', ['search' => 'Charity']))
        ->assertOk()->assertSee('Baksos Bandung')->assertDontSee('Kopdar Jakarta');

    $this->actingAs($this->admin)->get(route('gallery-albums.index', ['status' => 'published']))
        ->assertOk()->assertSee('Kopdar Jakarta')->assertDontSee('Baksos Bandung');
});

test('photo upload endpoint stores the photo and validates it', function () {
    $path = uploadGalleryPhoto();

    expect(GalleryPhoto::isUploadPath($path))->toBeTrue();
    Storage::disk(GalleryPhoto::DISK)->assertExists($path);

    foreach ([
        UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml'),
        UploadedFile::fake()->create('shell.jpg', 5, 'text/x-php'),
        UploadedFile::fake()->image('big.jpg')->size(11 * 1024),
    ] as $file) {
        $this->actingAs($this->admin)->postJson(route('gallery-albums.photo-uploads.store'), ['image' => $file])
            ->assertUnprocessable();
    }

    auth()->logout();
    $this->postJson(route('gallery-albums.photo-uploads.store'), ['image' => UploadedFile::fake()->image('a.jpg')])
        ->assertUnauthorized();
});

test('super admin can create an album with photos in order', function () {
    $this->actingAs($this->admin)->get(route('gallery-albums.create'))->assertOk();

    $payload = albumPayload();

    $this->actingAs($this->admin)->post(route('gallery-albums.store'), $payload)
        ->assertRedirect(route('gallery-albums.index'))->assertSessionHas('success');

    $album = GalleryAlbum::sole();

    expect($album->title)->toBe(['id' => 'Touring Bali 2026', 'en' => 'Bali Touring 2026'])
        ->and($album->gallery_date->format('Y-m-d'))->toBe('2026-09-20')
        ->and($album->status)->toBe(ContentStatus::Published)
        ->and($album->created_by)->toBe($this->admin->id)
        ->and($album->photos->pluck('image')->all())->toBe(array_column($payload['photos'], 'path'))
        ->and($album->photos->pluck('sort_order')->all())->toBe([1, 2]);

    Storage::disk(GalleryAlbum::DISK)->assertExists($album->image);
});

test('update reorders, adds and removes photos and deletes removed files', function () {
    $this->actingAs($this->admin)->post(route('gallery-albums.store'), albumPayload());
    $album = GalleryAlbum::sole();
    [$first, $second] = $album->photos->all();
    $newPath = uploadGalleryPhoto('c.webp');

    $this->actingAs($this->admin)->get(route('gallery-albums.edit', $album))->assertOk()->assertSee(basename($second->image), false);

    $this->actingAs($this->admin)->put(route('gallery-albums.update', $album), albumPayload([
        'image' => null,
        'photos' => [['path' => $newPath], ['id' => $second->id]], // foto pertama dihapus, foto baru di depan
    ]))->assertRedirect(route('gallery-albums.index'))->assertSessionHasNoErrors();

    $photos = $album->fresh()->photos;

    expect($photos->pluck('image')->all())->toBe([$newPath, $second->image])
        ->and($photos->pluck('sort_order')->all())->toBe([1, 2])
        ->and(GalleryPhoto::find($first->id))->toBeNull();

    Storage::disk(GalleryAlbum::DISK)->assertMissing($first->image);
    Storage::disk(GalleryAlbum::DISK)->assertExists($album->fresh()->image); // cover lama dipertahankan
});

test('replacing the cover deletes the old cover', function () {
    $this->actingAs($this->admin)->post(route('gallery-albums.store'), albumPayload());
    $album = GalleryAlbum::sole();
    $oldCover = $album->image;

    $this->actingAs($this->admin)->put(route('gallery-albums.update', $album), albumPayload([
        'image' => UploadedFile::fake()->image('cover2.png', 1200, 900),
        'photos' => $album->photos->map(fn ($photo) => ['id' => $photo->id])->all(),
    ]))->assertSessionHasNoErrors();

    Storage::disk(GalleryAlbum::DISK)->assertMissing($oldCover);
    Storage::disk(GalleryAlbum::DISK)->assertExists($album->fresh()->image);
});

test('photo paths that were not uploaded through the app are rejected', function (Closure $photos) {
    $this->actingAs($this->admin)->post(route('gallery-albums.store'), albumPayload(['photos' => $photos()]))
        ->assertSessionHasErrors();

    expect(GalleryAlbum::count())->toBe(0);
})->with([
    'path traversal' => [fn () => [['path' => '../../.env']]],
    'other directory' => [fn () => [['path' => 'hero-banners/2026/10/'.Str::random(40).'.jpg']]],
    'well formed but missing file' => [fn () => [['path' => GalleryPhoto::DIRECTORY.'/2026/10/'.Str::random(40).'.jpg']]],
    'duplicate path' => [fn () => [['path' => $p = uploadGalleryPhoto()], ['path' => $p]]],
    'id on create' => [fn () => [['id' => 1]]],
    'no photos' => [fn () => []],
]);

test('cannot reuse a photo already used by another album or hijack its id', function () {
    $this->actingAs($this->admin)->post(route('gallery-albums.store'), albumPayload());
    $otherPhoto = GalleryAlbum::sole()->photos->first();

    $album = GalleryAlbum::factory()->create();
    GalleryPhoto::factory()->for($album, 'album')->create();

    $this->actingAs($this->admin)->put(route('gallery-albums.update', $album), albumPayload([
        'photos' => [['path' => $otherPhoto->image]],
    ]))->assertSessionHasErrors('photos.0.path');

    $this->actingAs($this->admin)->put(route('gallery-albums.update', $album), albumPayload([
        'photos' => [['id' => $otherPhoto->id]],
    ]))->assertSessionHasErrors('photos.0.id');

    expect($otherPhoto->fresh()->gallery_album_id)->not->toBe($album->id);
});

test('validation rejects invalid album fields', function (Closure $overrides, string $errorField) {
    $this->actingAs($this->admin)->post(route('gallery-albums.store'), albumPayload($overrides()))
        ->assertSessionHasErrors($errorField);
})->with([
    'missing title id' => [fn () => ['title' => ['id' => '', 'en' => 'X']], 'title.id'],
    'unknown locale key' => [fn () => ['title' => ['id' => 'X', 'fr' => 'Y']], 'title'],
    'missing date' => [fn () => ['gallery_date' => ''], 'gallery_date'],
    'invalid date' => [fn () => ['gallery_date' => '20-09-2026'], 'gallery_date'],
    'missing cover' => [fn () => ['image' => null], 'image'],
    'svg cover' => [fn () => ['image' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')], 'image'],
    'invalid status' => [fn () => ['status' => 'archived'], 'status'],
]);

test('delete removes the album, its photos and all files', function () {
    $this->actingAs($this->admin)->post(route('gallery-albums.store'), albumPayload());
    $album = GalleryAlbum::sole();
    $files = [$album->image, ...$album->photos->pluck('image')->all()];

    $this->actingAs($this->admin)->delete(route('gallery-albums.destroy', $album))
        ->assertRedirect(route('gallery-albums.index'));

    expect(GalleryAlbum::count())->toBe(0)->and(GalleryPhoto::count())->toBe(0);
    Storage::disk(GalleryAlbum::DISK)->assertMissing($files);
});

test('prune command deletes only old uploads that are not used by any album', function () {
    $this->actingAs($this->admin)->post(route('gallery-albums.store'), albumPayload());
    $used = GalleryPhoto::first()->image;
    $orphan = uploadGalleryPhoto('orphan.jpg');
    $fresh = uploadGalleryPhoto('fresh.jpg');

    $disk = Storage::disk(GalleryPhoto::DISK);
    touch($disk->path($used), now()->subDays(3)->getTimestamp());
    touch($disk->path($orphan), now()->subDays(3)->getTimestamp());

    $this->artisan('gallery:prune-uploads', ['--dry-run' => true])->assertSuccessful();
    $disk->assertExists($orphan);

    $this->artisan('gallery:prune-uploads')->assertSuccessful();

    $disk->assertExists($used);
    $disk->assertExists($fresh);
    $disk->assertMissing($orphan);
});

test('policy follows gallery-albums.* permissions for non super admin roles', function () {
    $editor = User::factory()->create();
    $album = GalleryAlbum::factory()->create();

    expect($editor->can('viewAny', GalleryAlbum::class))->toBeFalse();

    $editor->givePermissionTo('gallery-albums.view', 'gallery-albums.update');

    expect($editor->can('viewAny', GalleryAlbum::class))->toBeTrue()
        ->and($editor->can('update', $album))->toBeTrue()
        ->and($editor->can('create', GalleryAlbum::class))->toBeFalse()
        ->and($editor->can('delete', $album))->toBeFalse();
});
