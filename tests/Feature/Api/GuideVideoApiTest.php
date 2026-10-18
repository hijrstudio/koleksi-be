<?php

use App\Models\GuideCategory;
use App\Models\GuideVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
    $this->category = GuideCategory::factory()->published()->create(['name' => ['id' => 'Kendaraan', 'en' => 'Vehicle']]);
});

function video(array $attrs = [], ?GuideCategory $category = null): GuideVideo
{
    return GuideVideo::factory()->published()
        ->for($category ?? test()->category, 'category')
        ->create($attrs);
}

test('video endpoints require frontend token', function () {
    $this->getJson(route('api.v1.guides.videos.index'))->assertUnauthorized();
    $this->getJson(route('api.v1.guides.videos.categories'))->assertUnauthorized();
});

test('index paginates 4 per page, newest first, visible only', function () {
    foreach (range(1, 5) as $i) {
        video(['created_at' => now()->subDays($i), 'title' => ['id' => "Video $i", 'en' => "Video $i"]]);
    }
    GuideVideo::factory()->for($this->category, 'category')->create(); // draft
    video([], GuideCategory::factory()->create()); // kategori draft

    $this->withToken('test-token')->getJson(route('api.v1.guides.videos.index'))
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.title.id', 'Video 1')
        ->assertJsonPath('data.0.category.name.id', 'Kendaraan')
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonStructure(['data' => [['id', 'title' => ['id', 'en'], 'image', 'youtube_id', 'category', 'published_at']]]);

    $this->withToken('test-token')->getJson(route('api.v1.guides.videos.index', ['page' => 2]))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title.id', 'Video 5');
});

test('index filters by category and searches title case-insensitively', function () {
    $other = GuideCategory::factory()->published()->create();
    $match = video(['title' => ['id' => 'Merawat Baterai', 'en' => 'Battery Care']]);
    video(['title' => ['id' => 'Tips Mengemudi', 'en' => 'Driving']], $other);

    $get = fn (array $q) => $this->withToken('test-token')->getJson(route('api.v1.guides.videos.index', $q));

    $get(['category' => $other->id])->assertJsonCount(1, 'data')->assertJsonPath('data.0.title.id', 'Tips Mengemudi');
    $get(['search' => 'BATERAI'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
    $get(['search' => 'battery care'])->assertJsonCount(1, 'data');
    $get(['search' => 'tidak ada'])->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
});

test('index query count stays constant (no N+1)', function () {
    foreach (range(1, 4) as $i) {
        video([], GuideCategory::factory()->published()->create());
    }

    DB::enableQueryLog();
    $this->withToken('test-token')->getJson(route('api.v1.guides.videos.index'))->assertOk();

    // count + data + category
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(3);
});

test('categories only include published categories that have published videos', function () {
    video();
    GuideCategory::factory()->published()->create(); // tanpa video
    $draftOnly = GuideCategory::factory()->published()->create();
    GuideVideo::factory()->for($draftOnly, 'category')->create(); // hanya video draft

    $this->withToken('test-token')->getJson(route('api.v1.guides.videos.categories'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name.en', 'Vehicle');
});
