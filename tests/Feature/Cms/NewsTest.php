<?php

use App\Enums\ContentStatus;
use App\Enums\NewsCategory;
use App\Models\GuideArticle;
use App\Models\Member;
use App\Models\News;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(News::DISK, ['url' => 'http://localhost/storage']);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

function newsPayload(array $overrides = []): array
{
    return array_merge([
        'category' => 'ev_news',
        'title' => ['id' => 'KOLEKSI Gelar Touring EV Jakarta-Bandung', 'en' => 'KOLEKSI Holds EV Touring Jakarta-Bandung'],
        'content' => ['id' => '<p>Isi <strong>berita</strong></p>', 'en' => '<p>News content</p>'],
        'status' => 'draft',
        'author_type' => 'user',
    ], $overrides);
}

test('guest cannot access news', function () {
    $this->get(route('news.index'))->assertRedirect(route('signin'));
});

test('category labels follow the requested names', function () {
    expect(collect(NewsCategory::cases())->map->label()->all())->toBe(['News Release', 'Berita EV', 'Liputan'])
        ->and(NewsCategory::EvNews->label('en'))->toBe('EV News');
});

test('index supports search and category, status & author filters', function () {
    News::factory()->published()->create(['category' => NewsCategory::NewsRelease, 'title' => ['id' => 'Rilis Resmi Munas', 'en' => 'Official Release']]);
    News::factory()->byMember(Member::factory()->create(['nama_lengkap' => 'Budi Wartawan']))
        ->create(['category' => NewsCategory::Coverage, 'title' => ['id' => 'Liputan Kopdar', 'en' => 'Meetup Coverage']]);

    $this->actingAs($this->admin)->get(route('news.index'))
        ->assertOk()->assertSee('Rilis Resmi Munas')->assertSee('Liputan Kopdar')->assertSee('Budi Wartawan');

    $this->actingAs($this->admin)->get(route('news.index', ['search' => 'Meetup']))
        ->assertOk()->assertSee('Liputan Kopdar')->assertDontSee('Rilis Resmi Munas');

    $this->actingAs($this->admin)->get(route('news.index', ['category' => 'news_release']))
        ->assertOk()->assertSee('Rilis Resmi Munas')->assertDontSee('Liputan Kopdar');

    $this->actingAs($this->admin)->get(route('news.index', ['status' => 'draft']))
        ->assertOk()->assertSee('Liputan Kopdar')->assertDontSee('Rilis Resmi Munas');

    $this->actingAs($this->admin)->get(route('news.index', ['author' => 'member']))
        ->assertOk()->assertSee('Liputan Kopdar')->assertDontSee('Rilis Resmi Munas');
});

test('admin can create news authored by themselves with auto slug', function () {
    $this->actingAs($this->admin)->get(route('news.create'))->assertOk();

    $this->actingAs($this->admin)->post(route('news.store'), newsPayload([
        'image' => UploadedFile::fake()->image('cover.jpg', 1200, 630),
        'status' => 'published',
    ]))->assertRedirect(route('news.index'))->assertSessionHas('success');

    $news = News::sole();

    expect($news->category)->toBe(NewsCategory::EvNews)
        ->and($news->slug)->toBe('koleksi-gelar-touring-ev-jakarta-bandung')
        ->and($news->status)->toBe(ContentStatus::Published)
        ->and($news->published_at)->not->toBeNull()
        ->and($news->author_user_id)->toBe($this->admin->id)
        ->and($news->author_member_id)->toBeNull()
        ->and($news->created_by)->toBe($this->admin->id)
        ->and($news->image)->toStartWith(News::DIRECTORY.'/')
        ->and($news->toArray())->toHaveKey('image_url');

    Storage::disk(News::DISK)->assertExists($news->image);
});

test('admin can create news authored by a member', function () {
    $member = Member::factory()->create();

    $this->actingAs($this->admin)->post(route('news.store'), newsPayload([
        'author_type' => 'member',
        'author_member_id' => $member->id,
    ]))->assertSessionHasNoErrors();

    expect(News::sole())->author_member_id->toBe($member->id)->author_user_id->toBeNull();
});

test('slug is unique per news and independent from article slugs', function () {
    // Slug sama di artikel panduan tidak bentrok dengan berita (tabel berbeda)
    GuideArticle::factory()->create()->forceFill(['slug' => 'koleksi-gelar-touring-ev-jakarta-bandung'])->save();

    $this->actingAs($this->admin)->post(route('news.store'), newsPayload(['slug' => 'koleksi-gelar-touring-ev-jakarta-bandung', 'slug_auto' => '1']));
    $this->actingAs($this->admin)->post(route('news.store'), newsPayload(['slug' => 'koleksi-gelar-touring-ev-jakarta-bandung', 'slug_auto' => '1']));

    expect(News::orderBy('id')->pluck('slug')->all())
        ->toBe(['koleksi-gelar-touring-ev-jakarta-bandung', 'koleksi-gelar-touring-ev-jakarta-bandung-2']);

    $this->actingAs($this->admin)->post(route('news.store'), newsPayload(['slug' => 'koleksi-gelar-touring-ev-jakarta-bandung', 'slug_auto' => '0']))
        ->assertSessionHasErrors('slug');
});

test('update changes fields, keeps slug and the original admin author', function () {
    $originalAdmin = User::factory()->create();
    $news = News::factory()->create(['title' => ['id' => 'KOLEKSI Gelar Touring EV Jakarta-Bandung', 'en' => null]]);
    $news->setAuthorUser($originalAdmin->id)->save();

    $this->actingAs($this->admin)->get(route('news.edit', $news))->assertOk()->assertSee($news->slug);

    $this->actingAs($this->admin)->put(route('news.update', $news), newsPayload([
        'category' => 'coverage',
        'title' => ['id' => 'Judul Baru', 'en' => null],
    ]))->assertRedirect(route('news.index'))->assertSessionHasNoErrors();

    expect($news->fresh())
        ->category->toBe(NewsCategory::Coverage)
        ->title->toBe(['id' => 'Judul Baru', 'en' => null])
        ->slug->toBe('koleksi-gelar-touring-ev-jakarta-bandung')
        ->author_user_id->toBe($originalAdmin->id)
        ->updated_by->toBe($this->admin->id);
});

test('replacing and removing the image deletes the old file', function () {
    $this->actingAs($this->admin)->post(route('news.store'), newsPayload(['image' => UploadedFile::fake()->image('a.jpg')]));
    $news = News::sole();
    $first = $news->image;

    $this->actingAs($this->admin)->put(route('news.update', $news), newsPayload(['image' => UploadedFile::fake()->image('b.png')]));
    $second = $news->fresh()->image;
    Storage::disk(News::DISK)->assertMissing($first);

    $this->actingAs($this->admin)->put(route('news.update', $news), newsPayload(['remove_image' => '1']));
    expect($news->fresh()->image)->toBeNull();
    Storage::disk(News::DISK)->assertMissing($second);
});

test('content image upload stores into the news content directory', function () {
    $response = $this->actingAs($this->admin)
        ->postJson(route('news.content-images.store'), ['image' => UploadedFile::fake()->image('foto.jpg', 800, 600)])
        ->assertCreated();

    expect($response->json('path'))->toStartWith(News::CONTENT_IMAGE_DIRECTORY.'/');
    Storage::disk(News::DISK)->assertExists($response->json('path'));

    $this->actingAs($this->admin)
        ->postJson(route('news.content-images.store'), ['image' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')])
        ->assertUnprocessable();
});

test('content keeps own images (including copied article images) and strips xss', function () {
    $newsImage = News::contentImageBaseUrl().'2026/10/berita.jpg';
    $articleImage = GuideArticle::contentImageBaseUrl().'2026/10/artikel.jpg';

    $this->actingAs($this->admin)->post(route('news.store'), newsPayload([
        'content' => ['id' => "<p onclick=\"x()\">Teks<script>alert(1)</script></p><img src=\"{$newsImage}\"><img src=\"{$articleImage}\"><img src=\"https://evil.test/a.gif\">", 'en' => null],
    ]))->assertSessionHasNoErrors();

    expect(News::sole()->content['id'])->toBe("<p>Teks</p><img src=\"{$newsImage}\" /><img src=\"{$articleImage}\" />");
});

test('validation rejects invalid input', function (Closure $overrides, string $errorField) {
    $this->actingAs($this->admin)->post(route('news.store'), newsPayload($overrides()))
        ->assertSessionHasErrors($errorField);

    expect(News::count())->toBe(0);
})->with([
    'missing category' => [fn () => ['category' => ''], 'category'],
    'unknown category' => [fn () => ['category' => 'gossip'], 'category'],
    'missing title id' => [fn () => ['title' => ['id' => '', 'en' => 'T']], 'title.id'],
    'empty editor content' => [fn () => ['content' => ['id' => '<p></p>', 'en' => null]], 'content.id'],
    'invalid author type' => [fn () => ['author_type' => 'guest'], 'author_type'],
    'member author without member' => [fn () => ['author_type' => 'member'], 'author_member_id'],
    'svg image' => [fn () => ['image' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')], 'image'],
    'invalid status' => [fn () => ['status' => 'archived'], 'status'],
]);

test('member options endpoint works for news writers', function () {
    Member::factory()->create(['nama_lengkap' => 'Siti Reporter', 'no_lambung' => 12]);

    $this->actingAs($this->admin)->getJson(route('news.member-options', ['q' => 'Siti']))
        ->assertOk()->assertJsonPath('results.0.label', 'Siti Reporter (#012)');
});

test('prune command keeps images used by news and by articles across modules', function () {
    $disk = Storage::disk(News::DISK);
    $old = now()->subDays(3)->getTimestamp();

    $files = [
        'used-news' => News::CONTENT_IMAGE_DIRECTORY.'/2026/10/used.jpg',
        'used-by-news-from-article' => GuideArticle::CONTENT_IMAGE_DIRECTORY.'/2026/10/shared.jpg',
        'orphan' => News::CONTENT_IMAGE_DIRECTORY.'/2026/10/orphan.jpg',
    ];
    foreach ($files as $path) {
        $disk->put($path, 'img');
        touch($disk->path($path), $old);
    }

    News::factory()->create(['content' => [
        'id' => '<img src="'.News::contentImageBaseUrl().'2026/10/used.jpg"><img src="'.GuideArticle::contentImageBaseUrl().'2026/10/shared.jpg">',
        'en' => null,
    ]]);

    $this->artisan('content:prune-images')->assertSuccessful();

    $disk->assertExists($files['used-news']);
    $disk->assertExists($files['used-by-news-from-article']);
    $disk->assertMissing($files['orphan']);
});

test('delete removes the news and its image', function () {
    $this->actingAs($this->admin)->post(route('news.store'), newsPayload(['image' => UploadedFile::fake()->image('a.jpg')]));
    $news = News::sole();

    $this->actingAs($this->admin)->delete(route('news.destroy', $news))->assertRedirect(route('news.index'));

    expect(News::count())->toBe(0);
    Storage::disk(News::DISK)->assertMissing($news->image);
});

test('policy follows news.* permissions for non super admin roles', function () {
    $editor = User::factory()->create();
    $news = News::factory()->create();

    expect($editor->can('viewAny', News::class))->toBeFalse();

    $editor->givePermissionTo('news.view', 'news.update');

    expect($editor->can('viewAny', News::class))->toBeTrue()
        ->and($editor->can('update', $news))->toBeTrue()
        ->and($editor->can('create', News::class))->toBeFalse()
        ->and($editor->can('delete', $news))->toBeFalse();
});
