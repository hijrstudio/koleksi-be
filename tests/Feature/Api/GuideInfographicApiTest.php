<?php

use App\Models\GuideCategory;
use App\Models\GuideInfographic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
    $this->category = GuideCategory::factory()->published()->create(['name' => ['id' => 'Teknologi', 'en' => 'Technology']]);
});

function infographic(array $attrs = [], ?GuideCategory $category = null): GuideInfographic
{
    return GuideInfographic::factory()->published()
        ->for($category ?? test()->category, 'category')
        ->create($attrs);
}

test('infographic endpoints require frontend token', function () {
    $this->getJson(route('api.v1.guides.infographics.index'))->assertUnauthorized();
    $this->getJson(route('api.v1.guides.infographics.categories'))->assertUnauthorized();
});

test('index paginates 6 per page, newest first, visible only', function () {
    foreach (range(1, 7) as $i) {
        infographic(['created_at' => now()->subDays($i), 'title' => ['id' => "Infografis $i", 'en' => "Infographic $i"]]);
    }
    GuideInfographic::factory()->for($this->category, 'category')->create(); // draft
    infographic([], GuideCategory::factory()->create()); // kategori draft

    $this->withToken('test-token')->getJson(route('api.v1.guides.infographics.index'))
        ->assertOk()
        ->assertJsonCount(6, 'data')
        ->assertJsonPath('data.0.title.id', 'Infografis 1')
        ->assertJsonPath('data.0.category.name.en', 'Technology')
        ->assertJsonPath('meta.total', 7)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonStructure(['data' => [['id', 'title' => ['id', 'en'], 'category', 'image', 'published_at']]]);
});

test('index filters by category and searches title case-insensitively', function () {
    $other = GuideCategory::factory()->published()->create();
    $match = infographic(['title' => ['id' => 'Komunitas Mobil Listrik', 'en' => 'EV Community']]);
    infographic(['title' => ['id' => 'KOLEKSI', 'en' => 'KOLEKSI']], $other);

    $get = fn (array $q) => $this->withToken('test-token')->getJson(route('api.v1.guides.infographics.index', $q));

    $get(['category' => $other->id])->assertJsonCount(1, 'data')->assertJsonPath('data.0.title.id', 'KOLEKSI');
    $get(['search' => 'mobil listrik'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
    $get(['search' => 'ev COMMUNITY'])->assertJsonCount(1, 'data');
});

test('index query count stays constant (no N+1)', function () {
    foreach (range(1, 6) as $i) {
        infographic([], GuideCategory::factory()->published()->create());
    }

    DB::enableQueryLog();
    $this->withToken('test-token')->getJson(route('api.v1.guides.infographics.index'))->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(3);
});

test('categories only include published categories that have published infographics', function () {
    infographic();
    GuideCategory::factory()->published()->create(); // tanpa infografis

    $this->withToken('test-token')->getJson(route('api.v1.guides.infographics.categories'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name.id', 'Teknologi');
});
