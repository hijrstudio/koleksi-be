<?php

use App\Models\ForumCategory;
use App\Models\ForumComment;
use App\Models\ForumDiscussion;
use App\Models\Member;
use App\Models\User;
use App\Notifications\ForumCommentReplied;
use App\Notifications\ForumDiscussionCommented;
use App\Notifications\NewForumDiscussion;
use App\Services\ForumService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const FORUM = '/api/v1/member/forum';

beforeEach(function () {
    Cache::flush();
    Storage::fake('public');
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Admin KOLEKSI']);
    $this->admin->assignRole('super-admin');
    $this->category = ForumCategory::where('slug', 'kendaraan')->firstOrFail();   // kategori awal dari migrasi
    $this->budi = Member::factory()->create(['no_lambung' => 1, 'nama_panggilan' => 'Budi']);
    $this->sari = Member::factory()->create(['no_lambung' => 2, 'nama_panggilan' => 'Sari']);
});

function asMember(Member $member): void
{
    Sanctum::actingAs($member, ['member']);
}

function discussionBy(Member|User $author, array $data = []): ForumDiscussion
{
    return app(ForumService::class)->createDiscussion($data + [
        'category_id' => ForumCategory::where('slug', 'kendaraan')->value('id'),
        'title' => 'Apakah EV pilihan tepat?',
        'body' => 'Mohon pendapatnya untuk mobil pertama.',
    ], $author);
}

test('the forum is only for logged-in members', function () {
    $this->getJson(FORUM.'/discussions')->assertUnauthorized();
    $this->postJson(FORUM.'/discussions')->assertUnauthorized();
});

test('a member creates a discussion with a cover image; admins get a bell notification only', function () {
    Notification::fake();
    asMember($this->budi);

    $res = $this->post(FORUM.'/discussions', [
        'category_id' => $this->category->id,
        'title' => "  Charging   101\u{202E} di rumah  ",
        'body' => "Baris satu\r\n\r\n\r\n\r\nBaris dua",
        'image' => UploadedFile::fake()->image('cover.jpg', 1600, 900),
    ], ['Accept' => 'application/json'])->assertCreated();

    $discussion = ForumDiscussion::firstOrFail();
    expect($discussion->title)->toBe('Charging 101 di rumah')
        ->and($discussion->body)->toBe("Baris satu\n\nBaris dua")
        ->and($discussion->member_id)->toBe($this->budi->id)
        ->and($discussion->slug)->toMatch('/^charging-101-di-rumah-[a-z0-9]{6}$/')
        ->and($res->json('data.author.role'))->toBe('Member')
        ->and($res->json('data.is_mine'))->toBeTrue();
    Storage::disk('public')->assertExists($discussion->image);
    expect(getimagesizefromstring(Storage::disk('public')->get($discussion->image))[0])->toBe(1200);   // diperkecil & WebP

    Notification::assertSentTo($this->admin, NewForumDiscussion::class, fn ($n, $channels) => $channels === ['database']);
});

test('discussion input is validated', function () {
    asMember($this->budi);
    $inactive = ForumCategory::create(['name' => 'Lama', 'slug' => 'lama', 'is_active' => false]);

    $this->postJson(FORUM.'/discussions', ['category_id' => $inactive->id, 'title' => 'abc', 'body' => 'pendek'])
        ->assertUnprocessable()->assertJsonValidationErrors(['category_id', 'title', 'body']);
    $this->post(FORUM.'/discussions', [
        'category_id' => $this->category->id, 'title' => 'Judul diskusi', 'body' => 'Isi diskusi yang cukup panjang',
        'image' => UploadedFile::fake()->create('virus.php', 10, 'application/x-php'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');
});

test('the list searches, filters by category, sorts, puts pinned first and hides moderated discussions', function () {
    $old = discussionBy($this->budi, ['title' => 'Perawatan baterai EV']);
    $old->forceFill(['created_at' => now()->subDay()])->save();
    $new = discussionBy($this->sari, ['title' => 'Charging di rumah']);
    $pinned = discussionBy($this->admin, ['title' => 'Panduan forum', 'category_id' => ForumCategory::where('slug', 'oot')->value('id')]);
    $pinned->forceFill(['is_pinned' => true, 'created_at' => now()->subDays(3)])->save();
    discussionBy($this->budi, ['title' => 'Spam'])->forceFill(['status' => ForumDiscussion::HIDDEN])->save();
    discussionBy($this->budi, ['title' => 'Dihapus'])->delete();
    asMember($this->budi);

    expect($this->getJson(FORUM.'/discussions')->json('data.*.slug'))->toBe([$pinned->slug, $new->slug, $old->slug])
        ->and($this->getJson(FORUM.'/discussions?sort=oldest')->json('data.*.slug'))->toBe([$pinned->slug, $old->slug, $new->slug])
        ->and($this->getJson(FORUM.'/discussions?search=baterai')->json('data.*.slug'))->toBe([$old->slug])
        ->and($this->getJson(FORUM.'/discussions?category=oot')->json('data.*.slug'))->toBe([$pinned->slug])
        ->and($this->getJson(FORUM.'/discussions')->json('data.0.author.role'))->toBe('Moderator');
});

test('hidden or deleted discussions are not found, and views count once per member per day', function () {
    $discussion = discussionBy($this->sari);
    asMember($this->budi);

    $this->getJson(FORUM.'/discussions/'.$discussion->slug)->assertOk()->assertJsonPath('data.views_count', 1);
    $this->getJson(FORUM.'/discussions/'.$discussion->slug)->assertJsonPath('data.views_count', 1);
    $this->travel(1)->days();
    $this->getJson(FORUM.'/discussions/'.$discussion->slug)->assertJsonPath('data.views_count', 2);

    $discussion->update(['status' => ForumDiscussion::HIDDEN]);
    $this->getJson(FORUM.'/discussions/'.$discussion->slug)->assertNotFound();
    $this->getJson(FORUM.'/discussions/nope')->assertNotFound();
});

test('comments and nested replies notify the right members, never themselves', function () {
    Notification::fake();
    $discussion = discussionBy($this->budi);
    $ani = Member::factory()->create(['no_lambung' => 3, 'nama_panggilan' => 'Ani']);

    asMember($this->sari);
    $first = $this->postJson(FORUM."/discussions/{$discussion->slug}/comments", ['body' => 'Setuju!'])->assertCreated()->json('data.id');
    Notification::assertSentTo($this->budi, ForumDiscussionCommented::class,
        fn ($n, $channels) => $channels === ['database'] && $n->toArray($this->budi)['discussion_slug'] === $discussion->slug);

    asMember($ani);
    $second = $this->postJson(FORUM."/discussions/{$discussion->slug}/comments", ['body' => 'Saya juga', 'parent_id' => $first])->json('data.id');
    Notification::assertSentTo($this->sari, ForumCommentReplied::class);
    Notification::assertSentToTimes($this->budi, ForumDiscussionCommented::class, 2);

    // Balasan di tingkat terdalam ditempel ke induk yang sama (maks 3 tingkat)
    asMember($this->budi);
    $third = $this->postJson(FORUM."/discussions/{$discussion->slug}/comments", ['body' => 'Terima kasih', 'parent_id' => $second])->json('data.id');
    $fourth = $this->postJson(FORUM."/discussions/{$discussion->slug}/comments", ['body' => 'Lanjut', 'parent_id' => $third])->json('data.id');
    expect(ForumComment::find($third)->depth)->toBe(2)
        ->and(ForumComment::find($fourth)->depth)->toBe(2)
        ->and(ForumComment::find($fourth)->parent_id)->toBe($second);
    Notification::assertSentToTimes($this->budi, ForumDiscussionCommented::class, 2);   // komentar sendiri: tidak memberi tahu diri

    $tree = $this->getJson(FORUM."/discussions/{$discussion->slug}/comments")->assertOk();
    expect($tree->json('data.0.replies.0.replies.0.id'))->toBe($third)
        ->and($tree->json('data.0.replies.0.replies.1.id'))->toBe($fourth)
        ->and($discussion->fresh()->comments_count)->toBe(4);
});

test('comment rules: no empty text, no reply across discussions, locked discussions, hidden comments masked', function () {
    $discussion = discussionBy($this->budi);
    $other = discussionBy($this->budi, ['title' => 'Diskusi lain']);
    $foreign = app(ForumService::class)->comment($other, $this->sari, 'Komentar lain');
    asMember($this->sari);

    $this->postJson(FORUM."/discussions/{$discussion->slug}/comments", ['body' => "  \n  "])->assertUnprocessable();
    $this->postJson(FORUM."/discussions/{$discussion->slug}/comments", ['body' => 'x', 'parent_id' => $foreign->id])
        ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

    $hidden = app(ForumService::class)->comment($discussion, $this->sari, 'Isi melanggar');
    $hidden->update(['status' => ForumComment::HIDDEN]);
    expect($this->getJson(FORUM."/discussions/{$discussion->slug}/comments")->json('data.0'))
        ->toMatchArray(['hidden' => true, 'body' => null, 'author' => null]);

    $discussion->update(['is_locked' => true]);
    $this->postJson(FORUM."/discussions/{$discussion->slug}/comments", ['body' => 'Masih bisa?'])->assertUnprocessable();
});

test('members rate other discussions once (re-rating replaces), not their own', function () {
    $discussion = discussionBy($this->budi);

    asMember($this->budi);
    $this->putJson(FORUM."/discussions/{$discussion->slug}/rating", ['rating' => 5])->assertUnprocessable();

    asMember($this->sari);
    $this->putJson(FORUM."/discussions/{$discussion->slug}/rating", ['rating' => 6])->assertUnprocessable();
    $this->putJson(FORUM."/discussions/{$discussion->slug}/rating", ['rating' => 2])->assertOk();
    $this->putJson(FORUM."/discussions/{$discussion->slug}/rating", ['rating' => 4])
        ->assertOk()->assertJsonPath('data', ['average' => 4, 'count' => 1, 'mine' => 4]);

    $this->getJson(FORUM."/discussions/{$discussion->slug}")->assertJsonPath('data.my_rating', 4);
    expect($discussion->fresh()->only(['rating_sum', 'rating_count']))->toBe(['rating_sum' => 4, 'rating_count' => 1]);
});

test('the overview gives counts, trending and most active members', function () {
    $discussion = discussionBy($this->budi);
    app(ForumService::class)->comment($discussion, $this->sari, 'Komentar');
    asMember($this->budi);

    $this->getJson(FORUM.'/overview')->assertOk()
        ->assertJsonPath('data.stats', ['discussions' => 1, 'comments' => 1, 'active_members' => 2])
        ->assertJsonPath('data.trending.0.slug', $discussion->slug)
        ->assertJsonPath('data.active_members.0.name', 'Sari');
});
