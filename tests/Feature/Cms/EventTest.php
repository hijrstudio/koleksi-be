<?php

use App\Enums\ContentStatus;
use App\Enums\EventPhase;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\User;
use App\Support\GeoCoordinates;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(Event::DISK);
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-10-08 10:00:00'); // "hari ini" dibekukan untuk perhitungan fase

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
    $this->category = EventCategory::factory()->published()->create(['name' => ['id' => 'Touring', 'en' => 'Touring']]);
});

function eventPayload(array $overrides = []): array
{
    return array_merge([
        'event_category_id' => test()->category->id,
        'title' => ['id' => 'Touring EV Jakarta Bandung', 'en' => 'EV Touring Jakarta Bandung'],
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-02',
        'description' => ['id' => '<p>Deskripsi <strong>event</strong></p>', 'en' => ''],
        'location' => 'Monas, Jakarta Pusat',
        'location_gmap' => 'https://www.google.com/maps/place/Monas/@-6.17,106.82,17z/data=!3d-6.1753924!4d106.8271528',
        'registration_info' => ['id' => '<p>Daftar via <a href="https://koleksi.id/daftar">form</a></p>', 'en' => null],
        'status' => 'published',
    ], $overrides);
}

// ---------- Kategori ----------

test('guest cannot access events and event categories', function () {
    $this->get(route('events.index'))->assertRedirect(route('signin'));
    $this->get(route('event-categories.index'))->assertRedirect(route('signin'));
});

test('super admin can manage event categories', function () {
    $this->actingAs($this->admin)->get(route('event-categories.index'))->assertOk()->assertSee('Touring');
    $this->actingAs($this->admin)->get(route('event-categories.create'))->assertOk();

    $this->actingAs($this->admin)->post(route('event-categories.store'), ['name' => ['id' => 'Kopdar', 'en' => ''], 'status' => 'draft'])
        ->assertRedirect(route('event-categories.index'));

    $category = EventCategory::where('name->id', 'Kopdar')->sole();
    expect($category->name)->toBe(['id' => 'Kopdar', 'en' => null])
        ->and($category->status)->toBe(ContentStatus::Draft)
        ->and($category->nameFor('en'))->toBe('Kopdar');

    $this->actingAs($this->admin)->get(route('event-categories.edit', $category))->assertOk();
    $this->actingAs($this->admin)->put(route('event-categories.update', $category), ['name' => ['id' => 'Kopdar', 'en' => 'Meetup'], 'status' => 'published'])
        ->assertSessionHasNoErrors();
    expect($category->fresh())
        ->name->toBe(['id' => 'Kopdar', 'en' => 'Meetup'])
        ->status->toBe(ContentStatus::Published);

    $this->actingAs($this->admin)->post(route('event-categories.store'), ['name' => ['id' => 'Kopdar', 'en' => null], 'status' => 'draft'])
        ->assertSessionHasErrors('name.id'); // duplikat

    $this->actingAs($this->admin)->delete(route('event-categories.destroy', $category))
        ->assertRedirect(route('event-categories.index'));
    expect(EventCategory::find($category->id))->toBeNull();
});

test('category that still has events cannot be deleted', function () {
    Event::factory()->for($this->category, 'category')->create();

    $this->actingAs($this->admin)->from(route('event-categories.index'))
        ->delete(route('event-categories.destroy', $this->category))
        ->assertSessionHas('error');

    expect($this->category->fresh())->not->toBeNull();
    $this->actingAs($this->admin)->get(route('event-categories.index'))->assertSee('1 event');
});

// ---------- Fase dari tanggal ----------

test('phase is derived from start and end dates', function (string $start, string $end, EventPhase $expected) {
    $event = Event::factory()->for($this->category, 'category')->between($start, $end)->create();

    expect($event->phase)->toBe($expected)
        ->and(Event::query()->phase($expected)->pluck('id')->all())->toBe([$event->id]);
})->with([
    'starts tomorrow' => ['2026-10-09', '2026-10-10', EventPhase::Upcoming],
    'starts today' => ['2026-10-08', '2026-10-08', EventPhase::Ongoing],
    'multi day running' => ['2026-10-01', '2026-10-15', EventPhase::Ongoing],
    'ends today' => ['2026-10-05', '2026-10-08', EventPhase::Ongoing],
    'ended yesterday' => ['2026-10-01', '2026-10-07', EventPhase::Done],
]);

test('phase moves automatically as time passes without updating data', function () {
    $event = Event::factory()->for($this->category, 'category')->between('2026-10-10', '2026-10-11')->create();

    expect($event->phase)->toBe(EventPhase::Upcoming);

    $this->travelTo('2026-10-10 08:00:00');
    expect($event->fresh()->phase)->toBe(EventPhase::Ongoing);

    $this->travelTo('2026-10-12 08:00:00');
    expect($event->fresh()->phase)->toBe(EventPhase::Done);
});

test('index tabs filter by phase and show counts', function () {
    Event::factory()->for($this->category, 'category')->between('2026-10-20')->create(['title' => ['id' => 'Event Akan Datang', 'en' => null]]);
    Event::factory()->for($this->category, 'category')->between('2026-10-07', '2026-10-09')->create(['title' => ['id' => 'Event Berlangsung', 'en' => null]]);
    Event::factory()->for($this->category, 'category')->between('2026-09-01')->create(['title' => ['id' => 'Event Selesai', 'en' => null]]);

    $this->actingAs($this->admin)->get(route('events.index'))
        ->assertOk()->assertSee('Event Akan Datang')->assertSee('Event Berlangsung')->assertSee('Event Selesai');

    foreach ([
        'upcoming' => ['Event Akan Datang', ['Event Berlangsung', 'Event Selesai']],
        'ongoing' => ['Event Berlangsung', ['Event Akan Datang', 'Event Selesai']],
        'done' => ['Event Selesai', ['Event Akan Datang', 'Event Berlangsung']],
    ] as $phase => [$see, $dontSee]) {
        $response = $this->actingAs($this->admin)->get(route('events.index', ['phase' => $phase]))->assertOk()->assertSee($see);
        foreach ($dontSee as $title) {
            $response->assertDontSee($title);
        }
    }
});

// ---------- CRUD event ----------

test('super admin can create an event with coordinates from a google maps link', function () {
    $this->actingAs($this->admin)->get(route('events.create'))->assertOk();

    $this->actingAs($this->admin)->post(route('events.store'), eventPayload())
        ->assertRedirect(route('events.index'))->assertSessionHas('success');

    $event = Event::sole();

    expect($event->slug)->toBe('touring-ev-jakarta-bandung')
        ->and($event->start_date->format('Y-m-d'))->toBe('2026-11-01')
        ->and($event->end_date->format('Y-m-d'))->toBe('2026-11-02')
        ->and($event->latitude)->toBe(-6.1753924)
        ->and($event->longitude)->toBe(106.8271528)
        ->and($event->maps_url)->toBe('https://www.google.com/maps?q=-6.1753924,106.8271528')
        ->and($event->description)->toBe(['id' => '<p>Deskripsi <strong>event</strong></p>', 'en' => null])
        ->and($event->status)->toBe(ContentStatus::Published)
        ->and($event->phase)->toBe(EventPhase::Upcoming)
        ->and($event->created_by)->toBe($this->admin->id)
        ->and($event->toArray())->toHaveKeys(['phase', 'maps_url']);
});

test('single day event gets end date equal to start date and coordinates are optional', function () {
    $this->actingAs($this->admin)->post(route('events.store'), eventPayload([
        'end_date' => '',
        'location_gmap' => '',
        'registration_info' => ['id' => '', 'en' => ''],
    ]))->assertSessionHasNoErrors();

    $event = Event::sole();

    expect($event->end_date->format('Y-m-d'))->toBe('2026-11-01')
        ->and($event->latitude)->toBeNull()
        ->and($event->maps_url)->toBeNull()
        ->and($event->registration_info)->toBeNull();
});

test('update keeps slug and can clear coordinates', function () {
    $this->actingAs($this->admin)->post(route('events.store'), eventPayload());
    $event = Event::sole();

    $this->actingAs($this->admin)->get(route('events.edit', $event))->assertOk()->assertSee('-6.1753924, 106.8271528');

    $this->actingAs($this->admin)->put(route('events.update', $event), eventPayload([
        'title' => ['id' => 'Judul Baru', 'en' => null],
        'location_gmap' => '',
    ]))->assertRedirect(route('events.index'))->assertSessionHasNoErrors();

    expect($event->fresh())
        ->slug->toBe('touring-ev-jakarta-bandung')
        ->title->toBe(['id' => 'Judul Baru', 'en' => null])
        ->latitude->toBeNull()
        ->updated_by->toBe($this->admin->id);
});

test('rich text fields are sanitized', function () {
    $this->actingAs($this->admin)->post(route('events.store'), eventPayload([
        'description' => ['id' => '<p onclick="x()">Halo<script>alert(1)</script><img src="https://evil.test/a.gif"></p>', 'en' => null],
    ]))->assertSessionHasNoErrors();

    expect(Event::sole()->description['id'])->toBe('<p>Halo</p>');
});

test('validation rejects invalid input', function (array $overrides, string $errorField) {
    $this->actingAs($this->admin)->post(route('events.store'), eventPayload($overrides))
        ->assertSessionHasErrors($errorField);

    expect(Event::count())->toBe(0);
})->with([
    'missing category' => [['event_category_id' => null], 'event_category_id'],
    'unknown category' => [['event_category_id' => 999], 'event_category_id'],
    'missing title id' => [['title' => ['id' => '', 'en' => 'X']], 'title.id'],
    'missing start date' => [['start_date' => ''], 'start_date'],
    'bad date format' => [['start_date' => '01-11-2026'], 'start_date'],
    'end before start' => [['start_date' => '2026-11-05', 'end_date' => '2026-11-01'], 'end_date'],
    'empty description' => [['description' => ['id' => '<p></p>', 'en' => null]], 'description.id'],
    'missing location' => [['location' => ''], 'location'],
    'short maps link' => [['location_gmap' => 'https://maps.app.goo.gl/AbCdEf123'], 'location_gmap'],
    'out of range coordinates' => [['location_gmap' => '123.4, 200.1'], 'location_gmap'],
    'invalid status' => [['status' => 'archived'], 'status'],
]);

test('google maps input formats are parsed into coordinates', function (string $input, ?array $expected) {
    expect(GeoCoordinates::parse($input))->toBe($expected);
})->with([
    'plain' => ['-6.175392, 106.827153', ['lat' => -6.175392, 'lng' => 106.827153]],
    'plain no space' => ['-6.175392,106.827153', ['lat' => -6.175392, 'lng' => 106.827153]],
    'place pin wins over view' => ['https://www.google.com/maps/place/X/@-6.1,106.8,17z/data=!3d-6.175392!4d106.827153', ['lat' => -6.175392, 'lng' => 106.827153]],
    'view' => ['https://www.google.com/maps/@-6.175392,106.827153,15z', ['lat' => -6.175392, 'lng' => 106.827153]],
    'query' => ['https://maps.google.com/?q=-6.175392,106.827153', ['lat' => -6.175392, 'lng' => 106.827153]],
    'encoded comma' => ['https://www.google.com/maps/search/?api=1&query=-6.175392%2C106.827153', ['lat' => -6.175392, 'lng' => 106.827153]],
    'short link' => ['https://maps.app.goo.gl/AbCdEf123', null],
    'text' => ['Monas Jakarta', null],
    'zero point' => ['0, 0', null],
    'latitude out of range' => ['91, 106', null],
]);

test('policies follow permissions for non super admin roles', function () {
    $editor = User::factory()->create();
    $event = Event::factory()->for($this->category, 'category')->create();

    expect($editor->can('viewAny', Event::class))->toBeFalse()
        ->and($editor->can('viewAny', EventCategory::class))->toBeFalse();

    $editor->givePermissionTo('events.view', 'events.update', 'event-categories.view');

    expect($editor->can('viewAny', Event::class))->toBeTrue()
        ->and($editor->can('update', $event))->toBeTrue()
        ->and($editor->can('delete', $event))->toBeFalse()
        ->and($editor->can('viewAny', EventCategory::class))->toBeTrue()
        ->and($editor->can('create', EventCategory::class))->toBeFalse();
});

test('delete removes the event', function () {
    $event = Event::factory()->for($this->category, 'category')->create();

    $this->actingAs($this->admin)->delete(route('events.destroy', $event))->assertRedirect(route('events.index'));

    expect(Event::count())->toBe(0);
});

test('category status is required and filterable', function () {
    EventCategory::factory()->create(['name' => ['id' => 'Kategori Draft', 'en' => null]]);

    $this->actingAs($this->admin)->post(route('event-categories.store'), ['name' => ['id' => 'Tanpa Status', 'en' => null]])
        ->assertSessionHasErrors('status');
    $this->actingAs($this->admin)->post(route('event-categories.store'), ['name' => ['id' => 'Status Aneh', 'en' => null], 'status' => 'archived'])
        ->assertSessionHasErrors('status');

    $this->actingAs($this->admin)->get(route('event-categories.index', ['status' => 'published']))
        ->assertOk()->assertSee('Touring')->assertDontSee('Kategori Draft');
    $this->actingAs($this->admin)->get(route('event-categories.index', ['status' => 'draft']))
        ->assertOk()->assertSee('Kategori Draft');
});

test('visible scope requires both event and category to be published', function () {
    $draftCategory = EventCategory::factory()->create();
    Event::factory()->published()->for($this->category, 'category')->create(['title' => ['id' => 'Tampil', 'en' => null]]);
    Event::factory()->published()->for($draftCategory, 'category')->create(['title' => ['id' => 'Kategori draft', 'en' => null]]);
    Event::factory()->for($this->category, 'category')->create(['title' => ['id' => 'Event draft', 'en' => null]]);

    expect(Event::visible()->get()->map->translate('title', 'id')->all())->toBe(['Tampil']);
});

test('event image can be uploaded, replaced, removed and is deleted with the event', function () {
    $this->actingAs($this->admin)->post(route('events.store'), eventPayload([
        'image' => UploadedFile::fake()->image('poster.jpg', 1080, 1350),
    ]))->assertSessionHasNoErrors();

    $event = Event::sole();
    $first = $event->image;

    expect($first)->toStartWith(Event::DIRECTORY.'/'.now()->format('Y/m').'/')
        ->and($event->image_url)->toBe(Storage::disk(Event::DISK)->url($first))
        ->and($event->toArray())->toHaveKey('image_url');
    Storage::disk(Event::DISK)->assertExists($first);

    // Edit tanpa gambar baru: gambar lama dipertahankan
    $this->actingAs($this->admin)->put(route('events.update', $event), eventPayload())->assertSessionHasNoErrors();
    expect($event->fresh()->image)->toBe($first);

    // Ganti gambar: file lama dihapus
    $this->actingAs($this->admin)->put(route('events.update', $event), eventPayload([
        'image' => UploadedFile::fake()->image('baru.png', 1080, 1350),
    ]))->assertSessionHasNoErrors();
    $second = $event->fresh()->image;
    Storage::disk(Event::DISK)->assertMissing($first);
    Storage::disk(Event::DISK)->assertExists($second);

    // Hapus gambar
    $this->actingAs($this->admin)->put(route('events.update', $event), eventPayload(['remove_image' => '1']))->assertSessionHasNoErrors();
    expect($event->fresh()->image)->toBeNull();
    Storage::disk(Event::DISK)->assertMissing($second);

    // Hapus event beserta gambarnya
    $this->actingAs($this->admin)->put(route('events.update', $event), eventPayload([
        'image' => UploadedFile::fake()->image('lagi.webp', 800, 800),
    ]));
    $third = $event->fresh()->image;
    $this->actingAs($this->admin)->delete(route('events.destroy', $event));
    Storage::disk(Event::DISK)->assertMissing($third);
});

test('event image rejects disallowed files', function (Closure $file) {
    $this->actingAs($this->admin)->post(route('events.store'), eventPayload(['image' => $file()]))
        ->assertSessionHasErrors('image');

    expect(Event::count())->toBe(0);
})->with([
    'svg' => [fn () => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')],
    'php disguised' => [fn () => UploadedFile::fake()->create('shell.jpg', 5, 'text/x-php')],
    'too large' => [fn () => UploadedFile::fake()->image('big.jpg')->size(6 * 1024)],
]);
