<?php

use App\Enums\ContentStatus;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\GuideArticle;
use App\Models\GuideCategory;
use App\Models\GuideVideo;
use App\Models\News;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config(['services.frontend.api_token' => 'test-token']);
    $guides = GuideCategory::factory()->published()->create(['name' => ['id' => 'Baterai', 'en' => 'Battery']]);
    $events = EventCategory::factory()->published()->create();

    $this->news = News::factory()->published()->create(['title' => ['id' => 'Harga Baterai Turun', 'en' => 'Battery prices fall'], 'published_at' => now()]);
    News::factory()->create(['title' => ['id' => 'Draft baterai rahasia', 'en' => 'Draft']]);   // draft: tidak tampil
    $this->article = GuideArticle::factory()->published()->for($guides, 'category')
        ->create(['title' => ['id' => 'Merawat BATERAI mobil listrik', 'en' => 'Battery care'], 'published_at' => now()]);
    GuideArticle::factory()->published()->for(GuideCategory::factory()->create(), 'category')   // kategori draft
        ->create(['title' => ['id' => 'Baterai di kategori tersembunyi', 'en' => 'Hidden']]);
    $this->event = Event::factory()->published()->for($events, 'category')
        ->create(['title' => ['id' => 'Workshop baterai', 'en' => 'Battery workshop'], 'location' => 'Jakarta']);
    $this->video = GuideVideo::factory()->published()->for($guides, 'category')
        ->create(['title' => ['id' => 'Video tes baterai', 'en' => 'Battery test video'], 'youtube_id' => 'dQw4w9WgXcQ']);
});

function search(array $query)
{
    return test()->withToken('test-token')->getJson(route('api.v1.search', $query));
}

test('search needs the frontend token and a keyword of at least 2 characters', function () {
    $this->getJson(route('api.v1.search', ['q' => 'baterai']))->assertUnauthorized();
    search(['q' => 'b'])->assertUnprocessable();
    search(['q' => str_repeat('a', 101)])->assertUnprocessable();
    search(['q' => 'baterai', 'type' => 'members'])->assertUnprocessable();
});

test('the overview finds visible content of every type, case-insensitively, with totals', function () {
    $res = search(['q' => 'baterai'])->assertOk()
        ->assertJsonPath('total', 4)
        ->assertJsonPath('data.0.type', 'news')
        ->assertJsonPath('data.0.total', 1)
        ->assertJsonPath('data.0.items.0.slug', $this->news->slug)
        ->assertJsonPath('data.1.items.0.title', 'Merawat BATERAI mobil listrik')
        ->assertJsonPath('data.1.items.0.meta', 'Baterai')
        ->assertJsonPath('data.2.items.0.meta', 'Jakarta')
        ->assertJsonPath('data.3.items.0.url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');

    expect($res->getContent())->not->toContain('rahasia', 'tersembunyi');

    // Bahasa Inggris: judul EN, dan kata kunci EN juga ditemukan
    search(['q' => 'battery', 'lang' => 'en'])->assertJsonPath('total', 4)->assertJsonPath('data.0.items.0.title', 'Battery prices fall');
});

test('one type is paginated', function () {
    foreach (range(1, 13) as $i) {
        News::factory()->published()->create(['title' => ['id' => "Mobil listrik ke-$i", 'en' => "EV $i"], 'published_at' => now()->subDays($i)]);
    }

    search(['q' => 'mobil listrik', 'type' => 'news'])->assertOk()
        ->assertJsonCount(12, 'data')
        ->assertJsonPath('meta.total', 13)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('data.0.title', 'Mobil listrik ke-1');
    search(['q' => 'mobil listrik', 'type' => 'news', 'page' => 2])->assertJsonCount(1, 'data');
});

test('wildcards in the keyword are treated as text', function () {
    search(['q' => '%%'])->assertOk()->assertJsonPath('total', 0);
    search(['q' => '__'])->assertOk()->assertJsonPath('total', 0);
});

test('unpublishing content removes it from new searches', function () {
    $this->news->update(['status' => ContentStatus::Draft]);
    search(['q' => 'harga baterai', 'type' => 'news'])->assertJsonPath('meta.total', 0);
});
