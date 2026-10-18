<?php

use App\Models\GuideArticle;
use App\Models\GuideCategory;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
    $this->category = GuideCategory::factory()->published()->create(['name' => ['id' => 'Kendaraan', 'en' => 'Vehicle']]);
});

function article(array $attrs = [], ?GuideCategory $category = null): GuideArticle
{
    return GuideArticle::factory()->published()
        ->for($category ?? test()->category, 'category')
        ->create(['published_at' => now(), ...$attrs]);
}

test('all article endpoints require frontend token', function () {
    $this->getJson(route('api.v1.guides.articles.index'))->assertUnauthorized();
    $this->getJson(route('api.v1.guides.articles.categories'))->assertUnauthorized();
    $this->getJson(route('api.v1.guides.articles.popular'))->assertUnauthorized();
    $this->putJson(route('api.v1.guides.articles.view', 'x'))->assertUnauthorized();
});

test('index paginates 5 per page, newest first, visible only', function () {
    foreach (range(1, 6) as $i) {
        article(['published_at' => now()->subDays($i), 'title' => ['id' => "Artikel $i", 'en' => "Article $i"]]);
    }
    GuideArticle::factory()->for($this->category, 'category')->create(); // draft
    article([], GuideCategory::factory()->create()); // kategori draft

    $page1 = $this->withToken('test-token')->getJson(route('api.v1.guides.articles.index'))
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('data.0.title.id', 'Artikel 1')
        ->assertJsonPath('data.0.category.name.id', 'Kendaraan')
        ->assertJsonPath('meta.has_more', true);

    $this->withToken('test-token')->getJson(route('api.v1.guides.articles.index', ['page' => 2]))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title.id', 'Artikel 6')
        ->assertJsonPath('meta.has_more', false);
});

test('index sorts oldest, filters category, searches title and author', function () {
    $other = GuideCategory::factory()->published()->create();
    $old = article(['published_at' => now()->subYear(), 'title' => ['id' => 'Baterai Awet', 'en' => 'Battery']]);
    article(['title' => ['id' => 'Tips Mengemudi', 'en' => 'Driving']], $other);
    article(['title' => ['id' => 'Lain', 'en' => 'Other']])->setAuthorMember(
        Member::factory()->create(['nama_lengkap' => 'Luki Cahyadi'])->id
    )->save();

    $get = fn (array $q) => $this->withToken('test-token')->getJson(route('api.v1.guides.articles.index', $q));

    $get(['sort' => 'oldest'])->assertJsonPath('data.0.id', $old->id);
    $get(['category' => $other->id])->assertJsonCount(1, 'data')->assertJsonPath('data.0.title.id', 'Tips Mengemudi');
    $get(['search' => 'baterai'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
    $get(['search' => 'BATERAI AWET'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
    $get(['search' => 'luki'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.title.id', 'Lain');
    $get(['sort' => 'random'])->assertUnprocessable();
});

test('index query count stays constant (no N+1)', function () {
    foreach (range(1, 5) as $i) {
        GuideArticle::factory()->published()->byMember()->for($this->category, 'category')->create(['published_at' => now()]);
    }

    DB::enableQueryLog();
    $this->withToken('test-token')->getJson(route('api.v1.guides.articles.index'))->assertOk();

    // artikel + category + authorMember + authorUser
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(4);
});

test('categories only include published categories that have published articles', function () {
    article();
    GuideCategory::factory()->published()->create(); // tanpa artikel
    $draftOnly = GuideCategory::factory()->published()->create();
    GuideArticle::factory()->for($draftOnly, 'category')->create(); // hanya artikel draft

    $this->withToken('test-token')->getJson(route('api.v1.guides.articles.categories'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name.en', 'Vehicle');
});

test('popular returns top 5 by views', function () {
    foreach ([10, 50, 30, 0, 20, 40] as $views) {
        $a = article();
        $a->forceFill(['views' => $views])->save();
    }
    $draft = GuideArticle::factory()->for($this->category, 'category')->create();
    $draft->forceFill(['views' => 999])->save();

    $this->withToken('test-token')->getJson(route('api.v1.guides.articles.popular'))
        ->assertOk()
        ->assertJsonPath('data.*.views', [50, 40, 30, 20, 10]);
});

test('show returns article content and related articles from same category', function () {
    $article = article(['content' => ['id' => '<p>Isi</p>', 'en' => null]]);
    article();
    article([], GuideCategory::factory()->published()->create()); // kategori lain

    $this->withToken('test-token')->getJson(route('api.v1.guides.articles.show', $article->slug))
        ->assertOk()
        ->assertJsonPath('data.id', $article->id)
        ->assertJsonPath('data.content.en', '<p>Isi</p>')
        ->assertJsonCount(1, 'related');
});

test('show returns 404 for draft article', function () {
    $draft = GuideArticle::factory()->for($this->category, 'category')->create();

    $this->withToken('test-token')->getJson(route('api.v1.guides.articles.show', $draft->slug))->assertNotFound();
});

test('view increments views without touching updated_at', function () {
    $article = article();
    $updatedAt = $article->updated_at;
    $this->travel(1)->hour();

    $this->withToken('test-token')->putJson(route('api.v1.guides.articles.view', $article->slug))
        ->assertOk()
        ->assertJsonPath('data.views', 1);
    $this->withToken('test-token')->putJson(route('api.v1.guides.articles.view', $article->slug))
        ->assertJsonPath('data.views', 2);

    expect($article->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

test('view returns 404 for draft or unknown article', function () {
    $draft = GuideArticle::factory()->for($this->category, 'category')->create();

    $this->withToken('test-token')->putJson(route('api.v1.guides.articles.view', $draft->slug))->assertNotFound();
    $this->withToken('test-token')->putJson(route('api.v1.guides.articles.view', 'tidak-ada'))->assertNotFound();

    expect($draft->fresh()->views)->toBe(0);
});
