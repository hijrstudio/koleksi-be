<?php

use App\Models\Sponsor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
});

test('requires frontend token', function () {
    $this->getJson(route('api.v1.sponsors.index'))->assertUnauthorized();
});

test('returns published sponsors ordered by sort', function () {
    $second = Sponsor::factory()->published()->create(['sort' => 2, 'title' => 'PLN Mobile']);
    $first = Sponsor::factory()->published()->create(['sort' => 1, 'title' => 'PLN']);
    Sponsor::factory()->create(['sort' => 0]); // draft

    $this->withToken('test-token')->getJson(route('api.v1.sponsors.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.*.id', [$first->id, $second->id])
        ->assertJsonPath('data.0.name', 'PLN')
        ->assertJsonStructure(['data' => [['id', 'name', 'logo', 'url']]]);
});

test('runs a single query', function () {
    Sponsor::factory()->count(5)->published()->create();

    DB::enableQueryLog();
    $this->withToken('test-token')->getJson(route('api.v1.sponsors.index'))->assertOk();

    expect(DB::getQueryLog())->toHaveCount(1);
});
