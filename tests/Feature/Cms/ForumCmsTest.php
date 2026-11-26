<?php

use App\Models\ForumCategory;
use App\Models\ForumComment;
use App\Models\ForumDiscussion;
use App\Models\Member;
use App\Models\User;
use App\Services\ForumService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Storage::fake('public');
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Admin KOLEKSI']);
    $this->admin->assignRole('super-admin');
    $this->category = ForumCategory::where('slug', 'kendaraan')->firstOrFail();
    $this->member = Member::factory()->create(['no_lambung' => 1, 'nama_panggilan' => 'Budi']);
});

function cmsDiscussion(array $overrides = []): array
{
    return $overrides + [
        'category_id' => ForumCategory::where('slug', 'kendaraan')->value('id'),
        'title' => 'Panduan forum KOLEKSI', 'body' => 'Selamat datang di forum, baca aturan ini.',
        'status' => 'published', 'is_pinned' => '1', 'is_locked' => '0',
    ];
}

test('admin creates a pinned discussion that shows as Moderator, then edits and hides it', function () {
    $this->actingAs($this->admin)->get(route('forum-discussions.create'))->assertOk();
    $this->actingAs($this->admin)->post(route('forum-discussions.store'), cmsDiscussion())->assertSessionHasNoErrors();

    $discussion = ForumDiscussion::firstOrFail();
    expect($discussion->user_id)->toBe($this->admin->id)
        ->and($discussion->is_pinned)->toBeTrue()
        ->and($discussion->authorPayload()['role'])->toBe('Moderator');

    $this->actingAs($this->admin)->get(route('forum-discussions.index'))->assertOk()->assertSee('Panduan forum KOLEKSI');
    $this->actingAs($this->admin)->put(route('forum-discussions.update', $discussion), cmsDiscussion(['status' => 'hidden', 'title' => 'Panduan forum (lama)']))
        ->assertRedirect(route('forum-discussions.show', $discussion));
    expect($discussion->fresh()->only(['status', 'title']))->toBe(['status' => 'hidden', 'title' => 'Panduan forum (lama)']);
});

test('admin moderates comments: hide keeps replies, delete removes the thread, counts follow', function () {
    $forum = app(ForumService::class);
    $discussion = $forum->createDiscussion(cmsDiscussion(), $this->member);
    $root = $forum->comment($discussion, $this->member, 'Komentar <script>alert(1)</script>');
    $reply = $forum->comment($discussion, $this->member, 'Balasan', $root->id);

    $this->actingAs($this->admin)->get(route('forum-discussions.show', $discussion))->assertOk()
        ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);

    $this->actingAs($this->admin)->put(route('forum-comments.toggle', $root));
    expect($root->fresh()->status)->toBe(ForumComment::HIDDEN)->and($discussion->fresh()->comments_count)->toBe(1);

    $this->actingAs($this->admin)->post(route('forum-discussions.comments.store', $discussion), ['body' => 'Mohon jaga etika', 'parent_id' => $reply->id]);
    $this->actingAs($this->admin)->delete(route('forum-comments.destroy', $root));
    expect(ForumComment::count())->toBe(0)->and($discussion->fresh()->comments_count)->toBe(0);
});

test('categories in use cannot be deleted; the forum menu needs the forum permissions', function () {
    app(ForumService::class)->createDiscussion(cmsDiscussion(), $this->member);

    $this->actingAs($this->admin)->delete(route('forum-categories.destroy', $this->category))->assertSessionHas('error');
    $this->actingAs($this->admin)->post(route('forum-categories.store'), ['name' => 'Touring', 'sort_order' => 9, 'is_active' => 1])
        ->assertRedirect(route('forum-categories.index'));
    expect(ForumCategory::where('slug', 'touring')->exists())->toBeTrue();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('members.view');
    expect($viewer->can('viewAny', ForumDiscussion::class))->toBeFalse()
        ->and($viewer->can('create', ForumCategory::class))->toBeFalse();
});

test('clicking a CMS notification opens its page on the CMS domain, never another site', function () {
    $discussion = app(ForumService::class)->createDiscussion(cmsDiscussion(), $this->member);
    $open = function (string $actionUrl) {
        $notification = $this->admin->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['action_url' => $actionUrl],
        ]);

        return $this->actingAs($this->admin)->get(route('notifications.open', $notification->id));
    };

    // action_url dibuat dengan APP_URL = domain API; dibuka di domain CMS yang sedang dipakai
    $open("https://api.koleksi.test/forum-discussions/{$discussion->id}?tab=komentar")
        ->assertRedirect(url("/forum-discussions/{$discussion->id}?tab=komentar"));
    $open('https://evil.test/phishing')->assertRedirect(url('/phishing'));
    $open('//evil.test/phishing')->assertRedirect(url('/phishing'));   // domain dibuang: tetap di CMS
    $open('javascript:alert(1)')->assertRedirect(route('dashboard'));
    expect($this->admin->unreadNotifications()->where('type', 'test')->count())->toBe(0);   // diklik = dibaca
});
