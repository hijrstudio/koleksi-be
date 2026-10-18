<?php

use App\Enums\NewsCategory;
use App\Models\News;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
});

test('requires frontend token', function () {
    $this->getJson(route('api.v1.news.latest'))->assertUnauthorized();
});

test('returns 3 latest published news only', function () {
    $oldest = News::factory()->published()->create(['published_at' => now()->subDays(4)]);
    News::factory()->published()->create(['published_at' => now()->subDays(3)]);
    $second = News::factory()->published()->create(['published_at' => now()->subDays(2)]);
    $newest = News::factory()->published()->create([
        'published_at' => now()->subDay(),
        'category' => NewsCategory::Coverage,
    ]);
    News::factory()->create(['published_at' => now()]); // draft

    $response = $this->withToken('test-token')->getJson(route('api.v1.news.latest'))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $newest->id)
        ->assertJsonPath('data.0.category.value', 'coverage')
        ->assertJsonPath('data.0.category.label.id', 'Liputan')
        ->assertJsonPath('data.1.id', $second->id)
        ->assertJsonStructure(['data' => [['id', 'category' => ['value', 'label' => ['id', 'en']], 'title' => ['id', 'en'], 'slug', 'image', 'published_at']]]);

    expect(collect($response->json('data'))->pluck('id'))->not->toContain($oldest->id);
});

test('runs a single query', function () {
    News::factory()->count(3)->published()->create();

    DB::enableQueryLog();
    $this->withToken('test-token')->getJson(route('api.v1.news.latest'))->assertOk();

    expect(DB::getQueryLog())->toHaveCount(1);
});
