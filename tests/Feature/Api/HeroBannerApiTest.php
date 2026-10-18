<?php

use App\Models\HeroBanner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
});

test('rejects request without or with wrong token', function () {
    $this->getJson(route('api.v1.hero-banners.index'))->assertUnauthorized();
    $this->withToken('wrong')->getJson(route('api.v1.hero-banners.index'))->assertUnauthorized();
});

test('rejects every request when token is not configured', function () {
    config(['services.frontend.api_token' => null]);

    $this->withToken('')->getJson(route('api.v1.hero-banners.index'))->assertUnauthorized();
});

test('returns only published banners, newest first', function () {
    $old = HeroBanner::factory()->published()->create(['title' => ['id' => 'Lama', 'en' => 'Old']]);
    HeroBanner::factory()->create(); // draft
    $new = HeroBanner::factory()->published()->create(['title' => ['id' => 'Baru', 'en' => 'New']]);

    $this->withToken('test-token')->getJson(route('api.v1.hero-banners.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $new->id)
        ->assertJsonPath('data.0.title.en', 'New')
        ->assertJsonPath('data.1.id', $old->id)
        ->assertJsonStructure(['data' => [['id', 'type', 'mime_type', 'url', 'title' => ['id', 'en'], 'updated_at']]]);
});
