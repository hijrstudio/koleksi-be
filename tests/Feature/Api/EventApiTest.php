<?php

use App\Models\Event;
use App\Models\EventCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
    $this->travelTo('2026-10-12 10:00:00');
    $this->category = EventCategory::factory()->published()->create();

    $this->make = fn (string $start, ?string $end = null, array $attrs = []) => Event::factory()
        ->published()
        ->between($start, $end)
        ->for($this->category, 'category')
        ->create($attrs);
});

test('requires frontend token', function () {
    $this->getJson(route('api.v1.events.index'))->assertUnauthorized();
    $this->getJson(route('api.v1.events.categories'))->assertUnauthorized();
});

test('lists all visible events without phase, latest start_date first', function () {
    $done = ($this->make)('2026-09-01', '2026-09-03');
    $ongoing = ($this->make)('2026-10-10', '2026-10-14');
    $upcoming = ($this->make)('2026-11-01');
    Event::factory()->between('2026-12-01')->for($this->category, 'category')->create(); // draft
    Event::factory()->published()->between('2026-12-02')->for(EventCategory::factory(), 'category')->create(); // kategori draft

    $this->withToken('test-token')->getJson(route('api.v1.events.index'))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$upcoming->id, $ongoing->id, $done->id])
        ->assertJsonPath('meta.total', 3)
        ->assertJsonStructure([
            'data' => [['id', 'title' => ['id', 'en'], 'slug', 'image', 'start_date', 'end_date', 'location', 'phase']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
});

test('filters by phase from start_date and end_date', function () {
    $done = ($this->make)('2026-09-01', '2026-10-11');
    $ongoingA = ($this->make)('2026-10-12');
    $ongoingB = ($this->make)('2026-10-01', '2026-10-20');
    $upcoming = ($this->make)('2026-10-13', '2026-10-15');

    $ids = fn (string $phase) => $this->withToken('test-token')
        ->getJson(route('api.v1.events.index', ['phase' => $phase]))
        ->assertOk()
        ->json('data.*.id');

    expect($ids('ongoing'))->toBe([$ongoingA->id, $ongoingB->id])
        ->and($ids('upcoming'))->toBe([$upcoming->id])
        ->and($ids('done'))->toBe([$done->id]);
});

test('searches title and location case-insensitively and filters category', function () {
    $other = EventCategory::factory()->published()->create();
    $touring = ($this->make)('2026-10-12', null, ['title' => ['id' => 'Touring Kemerdekaan', 'en' => 'Independence Touring']]);
    $pahawang = ($this->make)('2026-10-12', null, ['location' => 'Pulau Pahawang, Lampung']);
    $inOther = Event::factory()->published()->between('2026-10-12')->for($other, 'category')
        ->create(['title' => ['id' => 'Touring Bandung', 'en' => 'Bandung Touring']]);

    $this->withToken('test-token')->getJson(route('api.v1.events.index', ['search' => 'touring']))
        ->assertJsonCount(2, 'data');

    $this->withToken('test-token')->getJson(route('api.v1.events.index', ['search' => 'PAHAWANG']))
        ->assertJsonPath('data.*.id', [$pahawang->id]);

    $this->withToken('test-token')->getJson(route('api.v1.events.index', ['search' => 'touring', 'category' => $other->id]))
        ->assertJsonPath('data.*.id', [$inOther->id]);
});

test('paginates 8 per page by default', function () {
    Event::factory()->count(10)->published()->for($this->category, 'category')->create();

    $this->withToken('test-token')->getJson(route('api.v1.events.index', ['page' => 2]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.per_page', 8);
});

test('rejects invalid phase', function () {
    $this->withToken('test-token')->getJson(route('api.v1.events.index', ['phase' => 'soon']))
        ->assertUnprocessable();
});

test('lists published categories only', function () {
    EventCategory::factory()->create(); // draft

    $this->withToken('test-token')->getJson(route('api.v1.events.categories'))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$this->category->id])
        ->assertJsonStructure(['data' => [['id', 'name' => ['id', 'en']]]]);
});

test('list runs two queries', function () {
    Event::factory()->count(3)->published()->for($this->category, 'category')->create(['title' => ['id' => 'Acara', 'en' => 'Event']]);

    DB::enableQueryLog();
    $this->withToken('test-token')->getJson(route('api.v1.events.index', ['search' => 'a']))->assertOk();

    expect(DB::getQueryLog())->toHaveCount(2);
});

test('shows event detail with category, rich text and coordinates', function () {
    $event = ($this->make)('2026-10-12', '2026-10-14', [
        'description' => ['id' => '<p>Deskripsi</p>', 'en' => null],
        'registration_info' => ['id' => '<p>Daftar</p>', 'en' => '<p>Register</p>'],
        'location' => 'Pulau Pahawang, Lampung',
        'latitude' => -5.7167,
        'longitude' => 105.2167,
    ]);

    $this->withToken('test-token')->getJson(route('api.v1.events.show', $event->slug))
        ->assertOk()
        ->assertJsonPath('data.id', $event->id)
        ->assertJsonPath('data.phase', 'ongoing')
        ->assertJsonPath('data.category.id', $this->category->id)
        ->assertJsonPath('data.description', ['id' => '<p>Deskripsi</p>', 'en' => '<p>Deskripsi</p>'])
        ->assertJsonPath('data.registration_info.en', '<p>Register</p>')
        ->assertJsonPath('data.latitude', -5.7167)
        ->assertJsonPath('data.maps_url', 'https://www.google.com/maps?q=-5.7167,105.2167');
});

test('related events prefer the same category, then fill with others up to 4', function () {
    $other = EventCategory::factory()->published()->create();
    $event = ($this->make)('2026-10-12');
    $same = ($this->make)('2026-09-01');
    $others = collect(['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04'])
        ->map(fn ($d) => Event::factory()->published()->between($d)->for($other, 'category')->create());

    $this->withToken('test-token')->getJson(route('api.v1.events.show', $event->slug))
        ->assertJsonPath('related.*.id', [$same->id, $others[3]->id, $others[2]->id, $others[1]->id]);
});

test('detail returns 404 for draft events or draft categories', function () {
    $draft = Event::factory()->between('2026-10-12')->for($this->category, 'category')->create();
    $hiddenCategory = Event::factory()->published()->between('2026-10-12')->for(EventCategory::factory(), 'category')->create();

    $this->withToken('test-token')->getJson(route('api.v1.events.show', $draft->slug))->assertNotFound();
    $this->withToken('test-token')->getJson(route('api.v1.events.show', $hiddenCategory->slug))->assertNotFound();
    $this->withToken('test-token')->getJson(route('api.v1.events.show', 'tidak-ada'))->assertNotFound();
});
