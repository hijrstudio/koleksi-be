<?php

use App\Enums\NewsCategory;
use App\Models\Member;
use App\Models\News;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
});

function news(array $attrs = []): News
{
    return News::factory()->published()->create([
        'category' => NewsCategory::NewsRelease,
        'published_at' => now(),
        ...$attrs,
    ]);
}

test('news endpoints require frontend token', function () {
    $this->getJson(route('api.v1.news.index'))->assertUnauthorized();
    $this->getJson(route('api.v1.news.show', 'x'))->assertUnauthorized();
});

test('latest route is not captured by show route', function () {
    news();

    $this->withToken('test-token')->getJson(route('api.v1.news.latest'))->assertOk()->assertJsonCount(1, 'data');
});

test('index paginates 5 per page, newest first, published only', function () {
    foreach (range(1, 6) as $i) {
        news(['published_at' => now()->subDays($i), 'title' => ['id' => "Berita $i", 'en' => "News $i"]]);
    }
    News::factory()->create(); // draft

    $this->withToken('test-token')->getJson(route('api.v1.news.index'))
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('data.0.title.id', 'Berita 1')
        ->assertJsonPath('meta.total', 6)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonStructure(['data' => [['id', 'category' => ['value', 'label' => ['id', 'en']], 'title', 'author', 'slug', 'image', 'published_at']]]);

    $this->withToken('test-token')->getJson(route('api.v1.news.index', ['page' => 2, 'sort' => 'oldest']))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title.id', 'Berita 1');
});

test('index filters by category enum and searches title & author', function () {
    $ev = news(['category' => NewsCategory::EvNews, 'title' => ['id' => 'Mobil Listrik Baru', 'en' => 'New EV']]);
    news(['category' => NewsCategory::Coverage, 'title' => ['id' => 'Liputan Gathering', 'en' => 'Coverage']]);
    $byMember = News::factory()->published()->byMember(Member::factory()->create(['nama_lengkap' => 'Luki Cahyadi']))
        ->create(['category' => NewsCategory::NewsRelease, 'published_at' => now()]);

    $get = fn (array $q) => $this->withToken('test-token')->getJson(route('api.v1.news.index', $q));

    $get(['category' => 'ev_news'])->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ev->id)
        ->assertJsonPath('data.0.category.label.id', 'Berita EV');
    $get(['search' => 'LISTRIK'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ev->id);
    $get(['search' => 'luki'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $byMember->id);
    $get(['category' => 'unknown'])->assertUnprocessable();
});

test('index query count stays constant (no N+1)', function () {
    foreach (range(1, 5) as $i) {
        News::factory()->published()->byMember()->create(['published_at' => now()]);
    }

    DB::enableQueryLog();
    $this->withToken('test-token')->getJson(route('api.v1.news.index'))->assertOk();

    // count + berita + authorMember + authorUser
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(4);
});

test('show returns content and up to 3 related news from the same category', function () {
    $news = news(['category' => NewsCategory::Coverage, 'content' => ['id' => '<p>Isi</p>', 'en' => null]]);
    foreach (range(1, 4) as $i) {
        news(['category' => NewsCategory::Coverage]);
    }
    news(['category' => NewsCategory::EvNews]); // kategori lain

    $this->withToken('test-token')->getJson(route('api.v1.news.show', $news->slug))
        ->assertOk()
        ->assertJsonPath('data.id', $news->id)
        ->assertJsonPath('data.content.en', '<p>Isi</p>')
        ->assertJsonCount(3, 'related')
        ->assertJsonPath('related.*.category.value', ['coverage', 'coverage', 'coverage']);
});

test('show returns 404 for draft news', function () {
    $draft = News::factory()->create();

    $this->withToken('test-token')->getJson(route('api.v1.news.show', $draft->slug))->assertNotFound();
});

test('ev news is ordered by its source date, other news by publish date', function () {
    $ev = fn (string $title, string $publishedAt, string $sourceDate) => news([
        'category' => NewsCategory::EvNews,
        'title' => ['id' => $title, 'en' => $title],
        'content' => null,
        'published_at' => $publishedAt,
        'source_url' => 'https://media.test/'.$title,
        'source_name' => 'Media',
        'source_published_on' => $sourceDate,
    ]);

    // Dipublish di CMS paling akhir, tapi beritanya paling lama → urutan terakhir
    $old = $ev('lama', '2026-10-25 10:00:00', '2026-09-01');
    $new = $ev('baru', '2026-10-01 10:00:00', '2026-10-20');
    $release = news(['title' => ['id' => 'rilis', 'en' => 'rilis'], 'published_at' => '2026-10-10 10:00:00']);

    $ids = fn (array $query = []) => collect(
        $this->withToken('test-token')->getJson(route('api.v1.news.index', $query))->assertOk()->json('data')
    )->pluck('id')->all();

    expect($ids(['category' => 'ev_news']))->toBe([$new->id, $old->id])
        ->and($ids(['category' => 'ev_news', 'sort' => 'oldest']))->toBe([$old->id, $new->id])
        // Campuran: Berita EV memakai tanggal berita, News Release tanggal publish
        ->and($ids())->toBe([$new->id, $release->id, $old->id]);

    // Homepage: News Release terbaru, Berita EV terbaru & terlama (menurut tanggal berita)
    expect(collect($this->withToken('test-token')->getJson(route('api.v1.news.latest'))->json('data'))->pluck('id')->all())
        ->toBe([$release->id, $new->id, $old->id]);
});

test('ev news cards carry the summary and source link instead of the internal author', function () {
    $ev = news([
        'category' => NewsCategory::EvNews,
        'content' => null,
        'summary' => ['id' => 'Ringkasan berita', 'en' => null],
        'source_url' => 'https://www.kompas.com/berita-ev',
        'source_name' => 'Kompas.com',
        'source_author' => null,
        'source_published_on' => '2026-10-20',
    ]);
    news(['title' => ['id' => 'Rilis resmi', 'en' => 'Release']]);

    $this->withToken('test-token')->getJson(route('api.v1.news.index', ['category' => 'ev_news']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $ev->id)
        ->assertJsonPath('data.0.category.external', true)
        ->assertJsonPath('data.0.summary', ['id' => 'Ringkasan berita', 'en' => 'Ringkasan berita'])
        ->assertJsonPath('data.0.author', 'Kompas.com') // penulis kosong → nama media
        ->assertJsonPath('data.0.source', [
            'url' => 'https://www.kompas.com/berita-ev',
            'name' => 'Kompas.com',
            'author' => null,
            'published_on' => '2026-10-20',
        ]);

    // Pencarian juga mencakup nama media
    $this->withToken('test-token')->getJson(route('api.v1.news.index', ['search' => 'kompas']))
        ->assertOk()->assertJsonCount(1, 'data');

    // Kategori lain: summary & source null
    $this->withToken('test-token')->getJson(route('api.v1.news.index', ['category' => 'news_release']))
        ->assertJsonPath('data.0.category.external', false)
        ->assertJsonPath('data.0.summary', null)
        ->assertJsonPath('data.0.source', null);

    // Homepage (latest) juga membawa link sumber
    $this->withToken('test-token')->getJson(route('api.v1.news.latest'))
        ->assertOk()->assertJsonFragment(['url' => 'https://www.kompas.com/berita-ev']);
});
