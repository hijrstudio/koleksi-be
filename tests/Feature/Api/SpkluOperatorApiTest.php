<?php

use App\Models\SpkluOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
});

test('requires frontend token', function () {
    $this->getJson(route('api.v1.spklu-operators.index'))->assertUnauthorized();
    $this->getJson(route('api.v1.spklu-operators.brands'))->assertUnauthorized();
});

test('lists published operators by brand, 10 per page', function () {
    foreach (range(1, 12) as $i) {
        SpkluOperator::factory()->published()->create(['brand_spklu' => sprintf('Brand %02d', $i)]);
    }
    SpkluOperator::factory()->create(['brand_spklu' => 'Aaa Draft']);

    $this->withToken('test-token')->getJson(route('api.v1.spklu-operators.index'))
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('data.0.brand_spklu', 'Brand 01')
        ->assertJsonPath('meta.total', 12)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonStructure(['data' => [[
            'id', 'brand_spklu', 'nama_operator', 'logo', 'aplikasi', 'link_playstore', 'link_appstore',
        ]]]);

    $this->withToken('test-token')->getJson(route('api.v1.spklu-operators.index', ['sort' => 'desc', 'page' => 2]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.*.brand_spklu', ['Brand 02', 'Brand 01']);
});

test('searches brand, operator and app', function () {
    SpkluOperator::factory()->published()->create(['brand_spklu' => 'PLN', 'aplikasi' => 'PLN Mobile']);
    SpkluOperator::factory()->published()->create(['brand_spklu' => 'Voltron', 'nama_operator' => 'PT Voltron']);

    $this->withToken('test-token')->getJson(route('api.v1.spklu-operators.index', ['search' => 'mobile']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.brand_spklu', 'PLN');

    // wildcard LIKE di-escape
    $this->withToken('test-token')->getJson(route('api.v1.spklu-operators.index', ['search' => '%']))
        ->assertOk()->assertJsonCount(0, 'data');
});

test('rejects invalid query', function (array $query) {
    $this->withToken('test-token')->getJson(route('api.v1.spklu-operators.index', $query))
        ->assertUnprocessable();
})->with([
    'sort' => [['sort' => 'random']],
    'per page too large' => [['per_page' => 500]],
    'search too long' => [['search' => str_repeat('a', 101)]],
]);

test('brands returns every published brand A-Z in one query', function () {
    SpkluOperator::factory()->published()->create(['brand_spklu' => 'Voltron']);
    SpkluOperator::factory()->published()->create(['brand_spklu' => 'Casion', 'logo' => null]);
    SpkluOperator::factory()->create(['brand_spklu' => 'Draft']);

    DB::enableQueryLog();

    $this->withToken('test-token')->getJson(route('api.v1.spklu-operators.brands'))
        ->assertOk()
        ->assertJsonPath('data.*.brand_spklu', ['Casion', 'Voltron'])
        ->assertJsonPath('data.0.logo', null)
        ->assertJsonStructure(['data' => [['id', 'brand_spklu', 'logo']]]);

    expect(DB::getQueryLog())->toHaveCount(1);
});
