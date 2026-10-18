<?php

use App\Models\Event;
use App\Models\EventCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
    $this->category = EventCategory::factory()->published()->create();
});

test('requires frontend token', function () {
    $this->getJson(route('api.v1.events.latest'))->assertUnauthorized();
});

test('returns 4 visible events with the latest start_date first', function () {
    $make = fn (string $date) => Event::factory()->published()->between($date)->for($this->category, 'category')->create();

    $make('2026-09-01');
    $e2 = $make('2026-09-02');
    $e3 = $make('2026-09-03');
    $e4 = $make('2026-09-04');
    $e5 = $make('2026-12-05'); // akan datang, tetap ikut karena start_date paling baru
    Event::factory()->between('2026-12-31')->for($this->category, 'category')->create(); // draft
    Event::factory()->published()->between('2026-12-30')->for(EventCategory::factory(), 'category')->create(); // kategori draft

    $this->withToken('test-token')->getJson(route('api.v1.events.latest'))
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.*.id', [$e5->id, $e4->id, $e3->id, $e2->id])
        ->assertJsonPath('data.0.start_date', '2026-12-05')
        ->assertJsonStructure(['data' => [['id', 'title' => ['id', 'en'], 'slug', 'image', 'start_date', 'end_date', 'location', 'phase']]]);
});

test('runs a single query', function () {
    Event::factory()->count(4)->published()->for($this->category, 'category')->create();

    DB::enableQueryLog();
    $this->withToken('test-token')->getJson(route('api.v1.events.latest'))->assertOk();

    expect(DB::getQueryLog())->toHaveCount(1);
});
