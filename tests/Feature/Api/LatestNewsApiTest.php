<?php

use App\Enums\NewsCategory;
use App\Models\News;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
});

function evNews(array $attributes = []): News
{
    return News::factory()->published()->create([
        'category' => NewsCategory::EvNews,
        'source_url' => 'https://media.example/berita',
        'source_name' => 'Media',
        ...$attributes,
    ]);
}

test('requires frontend token', function () {
    $this->getJson(route('api.v1.news.latest'))->assertUnauthorized();
});

test('returns latest news release, latest ev news and oldest ev news in that order', function () {
    News::factory()->published()->create(['category' => NewsCategory::NewsRelease, 'published_at' => now()->subDays(3)]);
    $release = News::factory()->published()->create(['category' => NewsCategory::NewsRelease, 'published_at' => now()->subDay()]);
    News::factory()->create(['category' => NewsCategory::NewsRelease, 'published_at' => now()]); // draft
    News::factory()->published()->create(['category' => NewsCategory::Coverage, 'published_at' => now()]);

    // Berita EV diurutkan menurut tanggal berita, bukan tanggal publish
    $oldest = evNews(['source_published_on' => now()->subYear()->toDateString(), 'published_at' => now()]);
    evNews(['source_published_on' => now()->subMonth()->toDateString(), 'published_at' => now()->subDays(2)]);
    $latest = evNews(['source_published_on' => now()->toDateString(), 'published_at' => now()->subDays(5)]);

    $response = $this->withToken('test-token')->getJson(route('api.v1.news.latest'))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['data' => [['slot', 'id', 'category' => ['value', 'label' => ['id', 'en'], 'external'], 'title' => ['id', 'en'], 'slug', 'image', 'source', 'published_at']]]);

    expect(collect($response->json('data'))->map(fn ($i) => [$i['slot'], $i['id']])->all())
        ->toBe([['news_release', $release->id], ['latest', $latest->id], ['archive', $oldest->id]]);
});

test('does not repeat the only ev news', function () {
    evNews();

    $slots = collect($this->withToken('test-token')->getJson(route('api.v1.news.latest'))->json('data'))->pluck('slot')->all();

    expect($slots)->toBe(['latest']);
});

test('returns an empty list when nothing is published', function () {
    $this->withToken('test-token')->getJson(route('api.v1.news.latest'))->assertOk()->assertJsonCount(0, 'data');
});

test('query count stays constant (no N+1)', function () {
    News::factory()->count(2)->published()->create(['category' => NewsCategory::NewsRelease]);
    evNews();
    evNews();

    DB::enableQueryLog();
    $this->withToken('test-token')->getJson(route('api.v1.news.latest'))->assertOk();

    expect(DB::getQueryLog())->toHaveCount(3);
});
