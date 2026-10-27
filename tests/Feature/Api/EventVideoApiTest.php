<?php

use App\Models\EventVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
});

test('event videos require frontend token', function () {
    $this->getJson(route('api.v1.event-videos.index'))->assertUnauthorized();
});

test('index paginates 4 per page, newest first, published only', function () {
    foreach (range(1, 5) as $i) {
        EventVideo::factory()->published()->create(['created_at' => now()->subDays($i), 'title' => ['id' => "Video $i", 'en' => null]]);
    }
    EventVideo::factory()->create(['created_at' => now()]); // draft

    $this->withToken('test-token')->getJson(route('api.v1.event-videos.index'))
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.title', ['id' => 'Video 1', 'en' => 'Video 1'])
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonStructure(['data' => [['id', 'title' => ['id', 'en'], 'image', 'youtube_id', 'published_at']]]);

    $this->withToken('test-token')->getJson(route('api.v1.event-videos.index', ['page' => 2]))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title.id', 'Video 5');
});

test('index searches title case-insensitively', function () {
    $match = EventVideo::factory()->published()->create(['title' => ['id' => 'Touring Bandung', 'en' => 'Bandung Touring']]);
    EventVideo::factory()->published()->create(['title' => ['id' => 'Gathering', 'en' => 'Gathering']]);

    $get = fn (array $q) => $this->withToken('test-token')->getJson(route('api.v1.event-videos.index', $q));

    $get(['search' => 'BANDUNG'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
    $get(['search' => 'tidak ada'])->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
});

test('index rejects invalid query', function () {
    $this->withToken('test-token')->getJson(route('api.v1.event-videos.index', ['per_page' => 100]))
        ->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

test('index query count stays constant (no N+1)', function () {
    EventVideo::factory()->count(4)->published()->create();

    DB::enableQueryLog();
    $this->withToken('test-token')->getJson(route('api.v1.event-videos.index'))->assertOk();

    // count + data
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(2);
});
