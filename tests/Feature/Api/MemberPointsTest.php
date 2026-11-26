<?php

use App\Enums\ContentStatus;
use App\Models\ForumCategory;
use App\Models\ForumComment;
use App\Models\ForumDiscussion;
use App\Models\ForumPoll;
use App\Models\Member;
use App\Models\MemberPointLog;
use App\Models\MemberPointTotal;
use App\Models\News;
use App\Models\User;
use App\Services\ForumPollService;
use App\Services\ForumService;
use App\Services\PointService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Notification::fake();
    $this->travelTo('2026-11-26 03:00:00');   // 10:00 WIB
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
    $this->budi = Member::factory()->create(['no_lambung' => 1, 'nama_panggilan' => 'Budi']);
    $this->sari = Member::factory()->create(['no_lambung' => 2, 'nama_panggilan' => 'Sari']);
    $this->forum = app(ForumService::class);
});

function pointsOf(Member $member, string $period = 'all'): int
{
    return (int) MemberPointTotal::query()->where('member_id', $member->id)->where('period', $period)->value('points');
}

function forumTopic(Member|User $author): ForumDiscussion
{
    return app(ForumService::class)->createDiscussion([
        'category_id' => ForumCategory::where('slug', 'kendaraan')->value('id'),
        'title' => 'Apakah EV pilihan tepat?',
        'body' => 'Mohon pendapatnya untuk mobil pertama.',
    ], $author);
}

test('daily visit and each menu give points once per WIB day', function () {
    Sanctum::actingAs($this->budi, ['member']);

    $this->postJson('/api/v1/member/points/visit', ['path' => '/'])->assertOk()->assertJsonPath('data.earned', 5);
    $this->postJson('/api/v1/member/points/visit', ['path' => '/ev-garage'])->assertJsonPath('data.earned', 10);
    $this->postJson('/api/v1/member/points/visit', ['path' => '/ev-garage/'])->assertJsonPath('data.earned', 0);
    $this->postJson('/api/v1/member/points/visit', ['path' => '/kegiatan/galeri/foto'])->assertJsonPath('data.earned', 10);   // menu Galeri
    $this->postJson('/api/v1/member/points/visit', ['path' => '/forum/diskusi'])->assertJsonPath('data.earned', 0);          // bukan menu berpoin
    expect(pointsOf($this->budi))->toBe(25);

    // 17:00 UTC = 00:00 WIB hari berikutnya
    $this->travelTo('2026-11-26 17:00:00');
    $this->postJson('/api/v1/member/points/visit', ['path' => '/ev-garage'])->assertJsonPath('data.earned', 15);
    expect(pointsOf($this->budi))->toBe(40)->and(pointsOf($this->budi, '2026-11'))->toBe(40);   // 27 Nov masih November

    $this->postJson('/api/v1/member/points/visit', [])->assertUnprocessable();
});

test('forum discussions and comments on others give points with daily caps and spam rules', function () {
    $topic = forumTopic($this->sari);
    expect(pointsOf($this->sari))->toBe(10);

    $this->forum->comment($topic, $this->sari, 'Komentar di diskusi sendiri tidak dapat poin.');
    $this->forum->comment($topic, $this->budi, 'mantap');   // terlalu pendek
    expect(pointsOf($this->sari))->toBe(10)->and(pointsOf($this->budi))->toBe(0);

    foreach (range(1, 12) as $i) {
        $this->forum->comment($topic, $this->budi, "Pendapat saya nomor {$i} tentang mobil listrik.");
    }
    expect(pointsOf($this->budi))->toBe(50);   // maks 10 komentar berpoin per hari

    foreach (range(1, 4) as $i) {
        forumTopic($this->budi);
    }
    expect(pointsOf($this->budi))->toBe(80);   // maks 3 diskusi berpoin per hari

    // Admin tidak mendapat poin
    forumTopic($this->admin);
    expect(MemberPointLog::count())->toBe(1 + 10 + 3);
});

test('moderators hiding or deleting content take the points back, showing it again restores them', function () {
    $topic = forumTopic($this->sari);
    $comment = $this->forum->comment($topic, $this->budi, 'Komentar yang cukup panjang untuk poin.');
    expect(pointsOf($this->budi))->toBe(5);

    $this->actingAs($this->admin)->put(route('forum-comments.toggle', $comment));
    expect(pointsOf($this->budi))->toBe(0);
    $this->actingAs($this->admin)->put(route('forum-comments.toggle', $comment));
    expect(pointsOf($this->budi))->toBe(5);

    $this->actingAs($this->admin)->delete(route('forum-comments.destroy', $comment));
    expect(pointsOf($this->budi))->toBe(0)->and(ForumComment::withTrashed()->find($comment->id)->trashed())->toBeTrue();

    $this->actingAs($this->admin)->delete(route('forum-discussions.destroy', $topic));
    expect(pointsOf($this->sari))->toBe(0);

    // Aktivitas yang ditarik tidak bisa memberi poin baru
    expect(app(PointService::class)->award($this->sari, 'discussion', "discussion:{$topic->id}"))->toBeFalse();
});

test('joining a poll gives points once, changing the choice does not', function () {
    $polls = app(ForumPollService::class);
    $poll = $polls->save(new ForumPoll, [
        'type' => ForumPoll::POLLING, 'title' => 'Charging di mana?', 'status' => ForumPoll::PUBLISHED,
        'starts_at' => now()->subHour(), 'ends_at' => null, 'options' => ['Rumah', 'SPKLU'],
    ], $this->admin);
    [$a, $b] = $poll->options()->pluck('id')->all();

    $polls->vote($poll, $this->budi, $a);
    $polls->vote($poll->fresh(), $this->budi, $b);
    expect(pointsOf($this->budi))->toBe(5);
});

test('member authors get 100 points when their news is published, moved when the author changes', function () {
    $news = News::factory()->byMember($this->budi)->create();
    expect(pointsOf($this->budi))->toBe(0);   // masih draft

    $news->update(['status' => ContentStatus::Published]);
    $news->update(['title' => ['id' => 'Judul baru', 'en' => 'New title']]);
    expect(pointsOf($this->budi))->toBe(100);

    $news->setAuthorMember($this->sari->id)->save();
    expect(pointsOf($this->budi))->toBe(0)->and(pointsOf($this->sari))->toBe(100);

    $news->update(['status' => ContentStatus::Draft]);
    expect(pointsOf($this->sari))->toBe(0);
    $news->update(['status' => ContentStatus::Published]);
    expect(pointsOf($this->sari))->toBe(100);

    $news->delete();
    expect(pointsOf($this->sari))->toBe(0);
});

test('reading needs 180 server-side seconds on the same content, once per content', function () {
    $news = News::factory()->byMember($this->sari)->published()->create();
    $other = News::factory()->byMember($this->sari)->published()->create();
    Sanctum::actingAs($this->budi, ['member']);
    $body = ['type' => 'news', 'slug' => $news->slug];

    $this->postJson('/api/v1/member/points/read/start', $body)->assertOk()->assertJsonPath('data.seconds', 180);
    $this->travel(100)->seconds();
    $this->postJson('/api/v1/member/points/read/claim', $body)->assertJsonPath('data.earned', 0);

    // Membuka artikel lain mengganti sesi baca
    $this->postJson('/api/v1/member/points/read/start', ['type' => 'news', 'slug' => $other->slug]);
    $this->travel(200)->seconds();
    $this->postJson('/api/v1/member/points/read/claim', $body)->assertJsonPath('data.earned', 0);
    $this->postJson('/api/v1/member/points/read/claim', ['type' => 'news', 'slug' => $other->slug])->assertJsonPath('data.earned', 25);

    $this->postJson('/api/v1/member/points/read/start', $body);
    $this->travel(181)->seconds();
    $this->postJson('/api/v1/member/points/read/claim', $body)->assertJsonPath('data.earned', 25);
    $this->postJson('/api/v1/member/points/read/start', $body);
    $this->travel(181)->seconds();
    $this->postJson('/api/v1/member/points/read/claim', $body)->assertJsonPath('data.earned', 0);   // sekali per konten

    expect(pointsOf($this->budi))->toBe(50);
    $this->postJson('/api/v1/member/points/read/start', ['type' => 'news', 'slug' => 'tidak-ada'])->assertNotFound();
    $this->postJson('/api/v1/member/points/read/start', ['type' => 'discussion', 'slug' => 'x'])->assertUnprocessable();
});

test('the leaderboard ranks by points, earliest first on ties, and shows my rank', function () {
    $points = app(PointService::class);
    $dewi = Member::factory()->create(['no_lambung' => 3, 'nama_panggilan' => 'Dewi', 'email' => 'dewi@example.test']);
    $pending = Member::factory()->create(['no_lambung' => 4]);
    $pending->forceFill(['registration_status' => Member::REG_AWAITING_PAYMENT])->save();

    $points->award($this->sari, 'visit', 'visit:a');
    $this->travel(1)->minute();
    $points->award($dewi, 'visit', 'visit:a');                 // seri 5 poin, tapi lebih lambat
    $points->award($this->budi, 'menu', 'menu:berita:a');      // 10 poin
    $points->award($pending, 'menu', 'menu:berita:a');         // bukan anggota resmi

    Sanctum::actingAs($dewi, ['member']);
    $res = $this->getJson('/api/v1/member/forum/leaderboard')->assertOk()
        ->assertJsonPath('period', 'month')
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.name', 'Budi')
        ->assertJsonPath('data.1.name', 'Sari')
        ->assertJsonPath('data.2.name', 'Dewi')
        ->assertJsonPath('data.2.is_me', true)
        ->assertJsonPath('data.0.points', 10)
        ->assertJsonPath('me', ['rank' => 3, 'points' => 5])
        ->assertJsonFragment(['action' => 'read', 'points' => 25, 'daily_cap' => null]);
    expect($res->getContent())->not->toContain('dewi@example.test', 'member_id');

    // Bulan berikutnya: peringkat bulanan kosong, sepanjang waktu tetap
    Cache::flush();
    $this->travelTo('2026-12-02 03:00:00');
    $this->getJson('/api/v1/member/forum/leaderboard?period=month')->assertJsonCount(0, 'data')->assertJsonPath('me', null);
    $this->getJson('/api/v1/member/forum/leaderboard?period=all')->assertJsonCount(3, 'data');
});

test('the profile shows all-time XP', function () {
    app(PointService::class)->award($this->budi, 'visit', 'visit:a');
    app(PointService::class)->award($this->budi, 'menu', 'menu:berita:a');
    Sanctum::actingAs($this->budi, ['member']);

    $this->getJson('/api/v1/member/me')->assertOk()->assertJsonPath('data.xp', 15);
    Sanctum::actingAs($this->sari, ['member']);
    $this->getJson('/api/v1/member/me')->assertOk()->assertJsonPath('data.xp', 0);
});
