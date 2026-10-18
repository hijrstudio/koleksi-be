<?php

use App\Models\GuideArticle;
use App\Models\GuideCategory;
use App\Models\GuideVideo;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
    $this->category = GuideCategory::factory()->published()->create();
});

test('requires frontend token', function () {
    $this->getJson(route('api.v1.guides.latest'))->assertUnauthorized();
});

test('returns 3 latest articles and 3 latest videos, alternating', function () {
    GuideArticle::factory()->count(4)->published()->for($this->category, 'category')->create();
    GuideVideo::factory()->count(4)->published()->for($this->category, 'category')->create();
    GuideArticle::factory()->for($this->category, 'category')->create(); // draft
    GuideVideo::factory()->published()->for(GuideCategory::factory(), 'category')->create(); // kategori draft

    $response = $this->withToken('test-token')->getJson(route('api.v1.guides.latest'))->assertOk();

    expect(collect($response->json('data'))->pluck('type')->all())
        ->toBe(['article', 'video', 'article', 'video', 'article', 'video']);

    $response->assertJsonStructure(['data' => [['id', 'type', 'title' => ['id', 'en'], 'author', 'image', 'slug', 'youtube_id', 'published_at']]]);
});

test('appends leftovers when one type has fewer items', function () {
    GuideArticle::factory()->count(3)->published()->for($this->category, 'category')->create();
    GuideVideo::factory()->published()->for($this->category, 'category')->create();

    $types = collect($this->withToken('test-token')->getJson(route('api.v1.guides.latest'))->json('data'))->pluck('type')->all();

    expect($types)->toBe(['article', 'video', 'article', 'article']);
});

test('formats member author with KOLEKSI number', function () {
    $member = Member::factory()->create(['nama_lengkap' => 'Luki Cahyadi', 'no_lambung' => 104]);
    GuideArticle::factory()->published()->byMember($member)->for($this->category, 'category')->create();

    $this->withToken('test-token')->getJson(route('api.v1.guides.latest'))
        ->assertJsonPath('data.0.author', 'Luki Cahyadi (KOLEKSI 104)');
});

test('video has no author', function () {
    GuideVideo::factory()->published()->for($this->category, 'category')->create();

    $this->withToken('test-token')->getJson(route('api.v1.guides.latest'))
        ->assertJsonPath('data.0.author', null);
});

test('query count stays constant (no N+1)', function () {
    GuideArticle::factory()->count(3)->published()->byMember()->for($this->category, 'category')->create();
    GuideVideo::factory()->count(3)->published()->for($this->category, 'category')->create();

    DB::enableQueryLog();
    $this->withToken('test-token')->getJson(route('api.v1.guides.latest'))->assertOk();

    // artikel + authorMember + authorUser + video
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(4);
});
