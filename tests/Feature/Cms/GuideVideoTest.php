<?php

use App\Enums\ContentStatus;
use App\Models\GuideCategory;
use App\Models\GuideVideo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(GuideVideo::DISK);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
    $this->category = GuideCategory::factory()->published()->create();
});

function videoPayload(array $overrides = []): array
{
    return array_merge([
        'guide_category_id' => test()->category->id,
        'title' => ['id' => 'Cara Mengisi Daya di SPKLU', 'en' => 'How to Charge at a Public Station'],
        'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s',
        'status' => 'published',
    ], $overrides);
}

test('guest cannot access guide videos', function () {
    $this->get(route('guide-videos.index'))->assertRedirect(route('signin'));
});

test('youtube id is parsed from common url formats', function (string $input, ?string $expected) {
    expect(GuideVideo::parseYoutubeId($input))->toBe($expected);
})->with([
    'plain id' => ['dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'watch with params' => ['https://youtube.com/watch?feature=share&v=dQw4w9WgXcQ&list=PL123&t=10s', 'dQw4w9WgXcQ'],
    'mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'short link' => ['https://youtu.be/dQw4w9WgXcQ?si=abc', 'dQw4w9WgXcQ'],
    'without scheme' => ['youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'nocookie embed' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'live' => ['https://www.youtube.com/live/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
    'other site' => ['https://evil.test/watch?v=dQw4w9WgXcQ', null],
    'lookalike host' => ['https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ', null],
    'channel page' => ['https://www.youtube.com/@koleksi', null],
    'invalid id' => ['https://youtu.be/short', null],
    'javascript' => ['javascript:alert(1)', null],
    'empty' => ['', null],
]);

test('index lists videos and supports search, category & status filters', function () {
    $other = GuideCategory::factory()->create();
    GuideVideo::factory()->published()->for($this->category, 'category')
        ->create(['title' => ['id' => 'Tips Berkendara Hemat', 'en' => 'Efficient Driving'], 'youtube_id' => 'aaaaaaaaaaa']);
    GuideVideo::factory()->for($other, 'category')
        ->create(['title' => ['id' => 'Mengenal Konektor', 'en' => 'Connector Types'], 'youtube_id' => 'bbbbbbbbbbb']);

    $this->actingAs($this->admin)->get(route('guide-videos.index'))
        ->assertOk()->assertSee('Tips Berkendara Hemat')->assertSee('Mengenal Konektor');

    $this->actingAs($this->admin)->get(route('guide-videos.index', ['search' => 'Connector']))
        ->assertOk()->assertSee('Mengenal Konektor')->assertDontSee('Tips Berkendara Hemat');

    $this->actingAs($this->admin)->get(route('guide-videos.index', ['search' => 'https://youtu.be/aaaaaaaaaaa']))
        ->assertOk()->assertSee('Tips Berkendara Hemat')->assertDontSee('Mengenal Konektor');

    $this->actingAs($this->admin)->get(route('guide-videos.index', ['category' => $other->id]))
        ->assertOk()->assertSee('Mengenal Konektor')->assertDontSee('Tips Berkendara Hemat');

    $this->actingAs($this->admin)->get(route('guide-videos.index', ['status' => 'draft']))
        ->assertOk()->assertSee('Mengenal Konektor')->assertDontSee('Tips Berkendara Hemat');
});

test('super admin can create a video from a full youtube url', function () {
    $this->actingAs($this->admin)->get(route('guide-videos.create'))->assertOk();

    $this->actingAs($this->admin)->post(route('guide-videos.store'), videoPayload())
        ->assertRedirect(route('guide-videos.index'))->assertSessionHas('success');

    $video = GuideVideo::sole();

    expect($video->youtube_id)->toBe('dQw4w9WgXcQ')
        ->and($video->status)->toBe(ContentStatus::Published)
        ->and($video->thumbnail)->toBeNull()
        ->and($video->thumbnail_url)->toBe('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg')
        ->and($video->embed_url)->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->and($video->created_by)->toBe($this->admin->id)
        ->and($video->toArray())->toHaveKeys(['thumbnail_url', 'embed_url', 'watch_url']);
});

test('uploaded thumbnail is used and can be replaced or removed', function () {
    $this->actingAs($this->admin)->post(route('guide-videos.store'), videoPayload([
        'thumbnail' => UploadedFile::fake()->image('thumb.jpg', 1280, 720),
    ]));
    $video = GuideVideo::sole();
    $first = $video->thumbnail;

    Storage::disk(GuideVideo::DISK)->assertExists($first);
    expect($video->thumbnail_url)->toBe(Storage::disk(GuideVideo::DISK)->url($first));

    $this->actingAs($this->admin)->get(route('guide-videos.edit', $video))->assertOk()->assertSee('dQw4w9WgXcQ');

    $this->actingAs($this->admin)->put(route('guide-videos.update', $video), videoPayload([
        'thumbnail' => UploadedFile::fake()->image('new.png', 1280, 720),
    ]))->assertSessionHasNoErrors();
    $second = $video->fresh()->thumbnail;

    Storage::disk(GuideVideo::DISK)->assertMissing($first);
    Storage::disk(GuideVideo::DISK)->assertExists($second);

    $this->actingAs($this->admin)->put(route('guide-videos.update', $video), videoPayload(['remove_thumbnail' => '1']))
        ->assertSessionHasNoErrors();

    expect($video->fresh()->thumbnail)->toBeNull();
    Storage::disk(GuideVideo::DISK)->assertMissing($second);
});

test('validation rejects invalid input', function (Closure $overrides, string $errorField) {
    $this->actingAs($this->admin)->post(route('guide-videos.store'), videoPayload($overrides()))
        ->assertSessionHasErrors($errorField);

    expect(GuideVideo::count())->toBe(0);
})->with([
    'missing category' => [fn () => ['guide_category_id' => null], 'guide_category_id'],
    'unknown category' => [fn () => ['guide_category_id' => 999], 'guide_category_id'],
    'missing title id' => [fn () => ['title' => ['id' => '', 'en' => 'T']], 'title.id'],
    'unknown locale key' => [fn () => ['title' => ['id' => 'T', 'fr' => 'T']], 'title'],
    'missing youtube url' => [fn () => ['youtube_url' => ''], 'youtube_url'],
    'non youtube url' => [fn () => ['youtube_url' => 'https://vimeo.com/123456'], 'youtube_url'],
    'youtube channel url' => [fn () => ['youtube_url' => 'https://www.youtube.com/@koleksi'], 'youtube_url'],
    'svg thumbnail' => [fn () => ['thumbnail' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')], 'thumbnail'],
    'thumbnail too large' => [fn () => ['thumbnail' => UploadedFile::fake()->image('big.jpg')->size(3 * 1024)], 'thumbnail'],
    'invalid status' => [fn () => ['status' => 'archived'], 'status'],
]);

test('delete removes the video and its uploaded thumbnail', function () {
    $this->actingAs($this->admin)->post(route('guide-videos.store'), videoPayload([
        'thumbnail' => UploadedFile::fake()->image('thumb.jpg'),
    ]));
    $video = GuideVideo::sole();

    $this->actingAs($this->admin)->delete(route('guide-videos.destroy', $video))
        ->assertRedirect(route('guide-videos.index'));

    expect(GuideVideo::count())->toBe(0);
    Storage::disk(GuideVideo::DISK)->assertMissing($video->thumbnail);
});

test('category that still has videos cannot be deleted', function () {
    GuideVideo::factory()->for($this->category, 'category')->create();

    $this->actingAs($this->admin)->from(route('guide-categories.index'))
        ->delete(route('guide-categories.destroy', $this->category))
        ->assertSessionHas('error');

    expect($this->category->fresh())->not->toBeNull();

    $this->actingAs($this->admin)->get(route('guide-categories.index'))->assertOk()->assertSee('1 video');
});

test('visible scope requires both video and category to be published', function () {
    $draftCategory = GuideCategory::factory()->create();
    GuideVideo::factory()->published()->for($this->category, 'category')->create(['title' => ['id' => 'Tampil', 'en' => null]]);
    GuideVideo::factory()->published()->for($draftCategory, 'category')->create(['title' => ['id' => 'Kategori draft', 'en' => null]]);
    GuideVideo::factory()->for($this->category, 'category')->create(['title' => ['id' => 'Video draft', 'en' => null]]);

    expect(GuideVideo::visible()->get()->map->titleFor('id')->all())->toBe(['Tampil']);
});

test('policy follows guide-videos.* permissions for non super admin roles', function () {
    $editor = User::factory()->create();
    $video = GuideVideo::factory()->create();

    expect($editor->can('viewAny', GuideVideo::class))->toBeFalse();

    $editor->givePermissionTo('guide-videos.view', 'guide-videos.update');

    expect($editor->can('viewAny', GuideVideo::class))->toBeTrue()
        ->and($editor->can('update', $video))->toBeTrue()
        ->and($editor->can('create', GuideVideo::class))->toBeFalse()
        ->and($editor->can('delete', $video))->toBeFalse();
});
