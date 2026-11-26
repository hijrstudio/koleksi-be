<?php

use App\Enums\ContentStatus;
use App\Models\ForumCategory;
use App\Models\ForumDiscussion;
use App\Models\GuideArticle;
use App\Models\GuideCategory;
use App\Models\Member;
use App\Models\MemberBookmark;
use App\Models\News;
use App\Services\ForumService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const BOOKMARKS = '/api/v1/member/bookmarks';

beforeEach(function () {
    Cache::flush();
    $this->seed(RoleSeeder::class);
    $this->member = Member::factory()->create(['no_lambung' => 1]);
    $this->discussion = app(ForumService::class)->createDiscussion([
        'category_id' => ForumCategory::where('slug', 'kendaraan')->value('id'),
        'title' => 'Charging di rumah', 'body' => 'Bagaimana pengalaman Anda?',
    ], Member::factory()->create(['no_lambung' => 2]));
    $this->news = News::factory()->published()->create(['title' => ['id' => 'Berita EV', 'en' => 'EV News']]);
    $this->article = GuideArticle::factory()->published()
        ->for(GuideCategory::factory()->state(['status' => ContentStatus::Published]), 'category')
        ->create(['title' => ['id' => 'Perawatan baterai', 'en' => 'Battery care']]);
});

test('bookmarks are only for logged-in members', function () {
    $this->getJson(BOOKMARKS)->assertUnauthorized();
    $this->putJson(BOOKMARKS, ['type' => 'news', 'slug' => $this->news->slug])->assertUnauthorized();
});

test('a member saves discussions, news and articles, sees them newest first, and removes them', function () {
    Sanctum::actingAs($this->member, ['member']);

    foreach ([['discussion', $this->discussion->slug], ['news', $this->news->slug], ['article', $this->article->slug]] as [$type, $slug]) {
        $this->getJson(BOOKMARKS."/status?type={$type}&slug={$slug}")->assertJsonPath('data.saved', false);
        $this->putJson(BOOKMARKS, ['type' => $type, 'slug' => $slug])->assertOk()->assertJsonPath('data.saved', true);
        $this->putJson(BOOKMARKS, ['type' => $type, 'slug' => $slug])->assertOk();   // klik ganda: tetap 1
        $this->getJson(BOOKMARKS."/status?type={$type}&slug={$slug}")->assertJsonPath('data.saved', true);
    }
    expect(MemberBookmark::count())->toBe(3);

    $this->getJson(BOOKMARKS.'?lang=en')->assertOk()
        ->assertJsonPath('data.*.type', ['article', 'news', 'discussion'])
        ->assertJsonPath('data.0.title', 'Battery care')
        ->assertJsonPath('data.2.title', 'Charging di rumah');

    $this->deleteJson(BOOKMARKS, ['type' => 'news', 'slug' => $this->news->slug])->assertJsonPath('data.saved', false);
    expect($this->getJson(BOOKMARKS)->json('data.*.type'))->toBe(['article', 'discussion']);
});

test('hidden or unknown content cannot be saved and disappears from the list', function () {
    Sanctum::actingAs($this->member, ['member']);
    $this->putJson(BOOKMARKS, ['type' => 'discussion', 'slug' => $this->discussion->slug]);

    $this->discussion->update(['status' => ForumDiscussion::HIDDEN]);
    expect($this->getJson(BOOKMARKS)->json('data'))->toBe([]);

    $this->putJson(BOOKMARKS, ['type' => 'discussion', 'slug' => $this->discussion->slug])->assertNotFound();
    $this->putJson(BOOKMARKS, ['type' => 'event', 'slug' => 'x'])->assertUnprocessable();
    $this->putJson(BOOKMARKS, ['type' => 'news', 'slug' => '../etc/passwd'])->assertUnprocessable();
    $draft = News::factory()->create();
    $this->putJson(BOOKMARKS, ['type' => 'news', 'slug' => $draft->slug])->assertNotFound();
});

test('members only see and change their own bookmarks', function () {
    $other = Member::factory()->create(['no_lambung' => 3]);
    MemberBookmark::create(['member_id' => $other->id, 'bookmarkable_type' => 'news', 'bookmarkable_id' => $this->news->id]);
    Sanctum::actingAs($this->member, ['member']);

    expect($this->getJson(BOOKMARKS)->json('data'))->toBe([]);
    $this->getJson(BOOKMARKS."/status?type=news&slug={$this->news->slug}")->assertJsonPath('data.saved', false);
    $this->deleteJson(BOOKMARKS, ['type' => 'news', 'slug' => $this->news->slug])->assertOk();
    expect(MemberBookmark::where('member_id', $other->id)->count())->toBe(1);
});
