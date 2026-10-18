<?php

use App\Models\GalleryAlbum;
use App\Models\GalleryPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
});

test('requires frontend token', function () {
    $this->getJson(route('api.v1.gallery.albums.index'))->assertUnauthorized();
});

test('returns published albums newest gallery date first, 6 per page', function () {
    foreach (range(1, 7) as $i) {
        GalleryAlbum::factory()->published()->create([
            'gallery_date' => now()->subDays($i)->toDateString(),
            'title' => ['id' => "Album $i", 'en' => null],
        ]);
    }
    GalleryAlbum::factory()->create(['gallery_date' => now()->toDateString()]); // draft

    $this->withToken('test-token')->getJson(route('api.v1.gallery.albums.index'))
        ->assertOk()
        ->assertJsonCount(6, 'data')
        ->assertJsonPath('data.0.title.id', 'Album 1')
        ->assertJsonPath('data.0.title.en', 'Album 1')
        ->assertJsonPath('meta.total', 7)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonStructure(['data' => [['id', 'title' => ['id', 'en'], 'cover', 'gallery_date', 'photos']]]);

    $this->withToken('test-token')->getJson(route('api.v1.gallery.albums.index', ['page' => 2]))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title.id', 'Album 7');
});

test('returns album photos in sort order', function () {
    $album = GalleryAlbum::factory()->published()->create();
    $second = GalleryPhoto::factory()->for($album, 'album')->create(['sort_order' => 2]);
    $first = GalleryPhoto::factory()->for($album, 'album')->create(['sort_order' => 1]);

    $this->withToken('test-token')->getJson(route('api.v1.gallery.albums.index'))
        ->assertJsonPath('data.0.photos.*.id', [$first->id, $second->id]);
});

test('searches title case-insensitively', function () {
    $match = GalleryAlbum::factory()->published()->create(['title' => ['id' => 'Mini Touring Jakarta-Bogor', 'en' => 'Mini Touring']]);
    GalleryAlbum::factory()->published()->create(['title' => ['id' => 'Gathering', 'en' => 'Gathering']]);

    $this->withToken('test-token')->getJson(route('api.v1.gallery.albums.index', ['search' => 'JAKARTA']))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

test('query count stays constant (no N+1)', function () {
    GalleryAlbum::factory()->count(6)->published()->create()
        ->each(fn (GalleryAlbum $album) => GalleryPhoto::factory()->count(3)->for($album, 'album')->create());

    DB::enableQueryLog();
    $this->withToken('test-token')->getJson(route('api.v1.gallery.albums.index'))->assertOk();

    // count + album + foto
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(3);
});
