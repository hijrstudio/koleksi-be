<?php

use App\Enums\ContentStatus;
use App\Models\EventVideo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(EventVideo::DISK);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

function eventVideoPayload(array $overrides = []): array
{
    return array_merge([
        'title' => ['id' => 'Touring Jakarta - Bandung', 'en' => 'Jakarta - Bandung Touring'],
        'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ?si=abc',
        'status' => 'published',
    ], $overrides);
}

test('guest cannot access event videos', function () {
    $this->get(route('event-videos.index'))->assertRedirect(route('signin'));
});

test('index lists videos and supports search & status filters', function () {
    EventVideo::factory()->published()->create(['title' => ['id' => 'Gathering Akbar', 'en' => 'Grand Gathering']]);
    EventVideo::factory()->create(['title' => ['id' => 'Touring Draft', 'en' => null]]);

    $this->actingAs($this->admin)->get(route('event-videos.index'))
        ->assertOk()->assertSee('Video Kegiatan')->assertSee('Gathering Akbar')->assertSee('Touring Draft');

    $this->actingAs($this->admin)->get(route('event-videos.index', ['search' => 'grand']))
        ->assertSee('Gathering Akbar')->assertDontSee('Touring Draft');

    $this->actingAs($this->admin)->get(route('event-videos.index', ['status' => 'draft']))
        ->assertSee('Touring Draft')->assertDontSee('Gathering Akbar');
});

test('super admin can create a video from a youtube url', function () {
    $this->actingAs($this->admin)->get(route('event-videos.create'))->assertOk();

    $this->actingAs($this->admin)->post(route('event-videos.store'), eventVideoPayload())
        ->assertRedirect(route('event-videos.index'))->assertSessionHas('success');

    $video = EventVideo::sole();

    expect($video->youtube_id)->toBe('dQw4w9WgXcQ')
        ->and($video->title)->toBe(['id' => 'Touring Jakarta - Bandung', 'en' => 'Jakarta - Bandung Touring'])
        ->and($video->status)->toBe(ContentStatus::Published)
        ->and($video->thumbnail_url)->toBe('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg')
        ->and($video->created_by)->toBe($this->admin->id);
});

test('uploaded thumbnail is used and can be replaced or removed', function () {
    $this->actingAs($this->admin)->post(route('event-videos.store'), eventVideoPayload([
        'thumbnail' => UploadedFile::fake()->image('thumb.jpg', 1280, 720),
    ]));
    $video = EventVideo::sole();
    $first = $video->thumbnail;

    Storage::disk(EventVideo::DISK)->assertExists($first);
    expect($first)->toStartWith(EventVideo::DIRECTORY.'/');

    $this->actingAs($this->admin)->get(route('event-videos.edit', $video))->assertOk()->assertSee('dQw4w9WgXcQ');

    $this->actingAs($this->admin)->put(route('event-videos.update', $video), eventVideoPayload([
        'thumbnail' => UploadedFile::fake()->image('new.png', 1280, 720),
    ]))->assertSessionHasNoErrors();
    $second = $video->fresh()->thumbnail;

    Storage::disk(EventVideo::DISK)->assertMissing($first);
    Storage::disk(EventVideo::DISK)->assertExists($second);

    $this->actingAs($this->admin)->put(route('event-videos.update', $video), eventVideoPayload(['remove_thumbnail' => '1']))
        ->assertSessionHasNoErrors();

    expect($video->fresh()->thumbnail)->toBeNull();
    Storage::disk(EventVideo::DISK)->assertMissing($second);
});

test('validation rejects invalid input', function (Closure $overrides, string $errorField) {
    $this->actingAs($this->admin)->post(route('event-videos.store'), eventVideoPayload($overrides()))
        ->assertSessionHasErrors($errorField);

    expect(EventVideo::count())->toBe(0);
})->with([
    'missing title id' => [fn () => ['title' => ['id' => '', 'en' => 'T']], 'title.id'],
    'unknown locale key' => [fn () => ['title' => ['id' => 'T', 'fr' => 'T']], 'title'],
    'missing youtube url' => [fn () => ['youtube_url' => ''], 'youtube_url'],
    'non youtube url' => [fn () => ['youtube_url' => 'https://vimeo.com/123456'], 'youtube_url'],
    'svg thumbnail' => [fn () => ['thumbnail' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')], 'thumbnail'],
    'thumbnail over 5 MB' => [fn () => ['thumbnail' => UploadedFile::fake()->image('big.jpg')->size(5 * 1024 + 1)], 'thumbnail'],
    'invalid status' => [fn () => ['status' => 'archived'], 'status'],
]);

test('thumbnail up to 5 MB is accepted', function () {
    $this->actingAs($this->admin)->post(route('event-videos.store'), eventVideoPayload([
        'thumbnail' => UploadedFile::fake()->image('thumb.jpg')->size(5 * 1024),
    ]))->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get(route('event-videos.create'))->assertSee('maks 5 MB');
});

test('delete removes the video and its uploaded thumbnail', function () {
    $this->actingAs($this->admin)->post(route('event-videos.store'), eventVideoPayload([
        'thumbnail' => UploadedFile::fake()->image('thumb.jpg'),
    ]));
    $video = EventVideo::sole();

    $this->actingAs($this->admin)->delete(route('event-videos.destroy', $video))
        ->assertRedirect(route('event-videos.index'));

    expect(EventVideo::count())->toBe(0);
    Storage::disk(EventVideo::DISK)->assertMissing($video->thumbnail);
});

test('policy follows event-videos.* permissions for non super admin roles', function () {
    $editor = User::factory()->create();
    $video = EventVideo::factory()->create();

    expect($editor->can('viewAny', EventVideo::class))->toBeFalse();
    $this->actingAs($editor)->get(route('event-videos.index'))->assertForbidden();

    $editor->givePermissionTo('event-videos.view', 'event-videos.update');

    expect($editor->can('viewAny', EventVideo::class))->toBeTrue()
        ->and($editor->can('update', $video))->toBeTrue()
        ->and($editor->can('create', EventVideo::class))->toBeFalse()
        ->and($editor->can('delete', $video))->toBeFalse();

    $this->actingAs($editor)->post(route('event-videos.store'), eventVideoPayload())->assertForbidden();
});
