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
