<?php

use App\Models\GuideArticle;
use App\Models\GuideCategory;
use App\Models\GuidePlaybook;
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

test('returns the latest playbook, video and article in that order', function () {
    GuidePlaybook::factory()->published()->create(['created_at' => now()->subDays(3)]);
    $playbook = GuidePlaybook::factory()->published()->create(['created_at' => now()->subDay(), 'title' => ['id' => 'Playbook Baru', 'en' => null]]);
    GuidePlaybook::factory()->create(['created_at' => now()]); // draft

    GuideVideo::factory()->published()->for($this->category, 'category')->create(['created_at' => now()->subDays(2)]);
    $video = GuideVideo::factory()->published()->for($this->category, 'category')->create(['created_at' => now()->subHour()]);
    GuideVideo::factory()->published()->for(GuideCategory::factory(), 'category')->create(['created_at' => now()]); // kategori draft

    GuideArticle::factory()->published()->for($this->category, 'category')->create(['published_at' => now()->subDays(2)]);
    $article = GuideArticle::factory()->published()->for($this->category, 'category')->create(['published_at' => now()->subHour()]);
    GuideArticle::factory()->for($this->category, 'category')->create(); // draft

    $response = $this->withToken('test-token')->getJson(route('api.v1.guides.latest'))->assertOk()->assertJsonCount(3, 'data');

    expect(collect($response->json('data'))->map(fn ($i) => [$i['type'], $i['id']])->all())
        ->toBe([['playbook', $playbook->id], ['video', $video->id], ['article', $article->id]]);

    $response
        ->assertJsonStructure(['data' => [['id', 'type', 'title' => ['id', 'en'], 'author', 'image', 'slug', 'youtube_id', 'published_at']]])
        ->assertJsonPath('data.0.title', ['id' => 'Playbook Baru', 'en' => 'Playbook Baru'])
        ->assertJsonPath('data.0.image', $playbook->cover_url)
        ->assertJsonPath('data.0.author', null)
        ->assertJsonPath('data.1.youtube_id', $video->youtube_id)
        ->assertJsonPath('data.2.slug', $article->slug);
});

test('skips a type without published content', function () {
    GuideArticle::factory()->published()->for($this->category, 'category')->create();
    GuideVideo::factory()->published()->for($this->category, 'category')->create();

    $types = collect($this->withToken('test-token')->getJson(route('api.v1.guides.latest'))->json('data'))->pluck('type')->all();

    expect($types)->toBe(['video', 'article']);
});

test('returns an empty list when nothing is published', function () {
    $this->withToken('test-token')->getJson(route('api.v1.guides.latest'))->assertOk()->assertJsonCount(0, 'data');
});

test('formats member author with KOLEKSI number', function () {
    $member = Member::factory()->create(['nama_lengkap' => 'Luki Cahyadi', 'no_lambung' => 104]);
    GuideArticle::factory()->published()->byMember($member)->for($this->category, 'category')->create();

    $this->withToken('test-token')->getJson(route('api.v1.guides.latest'))
        ->assertJsonPath('data.0.author', 'Luki Cahyadi (KOLEKSI 104)');
});

test('query count stays constant (no N+1)', function () {
    GuidePlaybook::factory()->count(3)->published()->create();
    GuideArticle::factory()->count(3)->published()->byMember()->for($this->category, 'category')->create();
    GuideVideo::factory()->count(3)->published()->for($this->category, 'category')->create();

    DB::enableQueryLog();
    $this->withToken('test-token')->getJson(route('api.v1.guides.latest'))->assertOk();

    // playbook + video + artikel + authorMember + authorUser
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(5);
});
