<?php

use App\Models\ForumPoll;
use App\Models\Member;
use App\Models\User;
use App\Services\ForumPollService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Admin KOLEKSI']);
    $this->admin->assignRole('super-admin');
});

function cmsPoll(array $overrides = []): array
{
    return $overrides + [
        'type' => 'voting', 'title' => 'Apakah EV pilihan tepat?', 'status' => 'published',
        'starts_at' => '', 'ends_at' => now('Asia/Jakarta')->addDay()->format('Y-m-d\TH:i'),
        'options' => ['Ya', 'Tidak'],
    ];
}

test('admin creates a voting with WIB times, sees results and closes it early', function () {
    $this->actingAs($this->admin)->get(route('forum-polls.create'))->assertOk();
    $this->actingAs($this->admin)->post(route('forum-polls.store'), cmsPoll([
        'starts_at' => '2026-12-01T09:00', 'ends_at' => '2026-12-02T09:00',
    ]))->assertSessionHasNoErrors();

    $poll = ForumPoll::firstOrFail();
    expect($poll->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-01 02:00')
        ->and($poll->user_id)->toBe($this->admin->id)
        ->and($poll->options()->count())->toBe(2);

    $this->actingAs($this->admin)->get(route('forum-polls.index'))->assertOk()->assertSee('Apakah EV pilihan tepat?');
    $this->actingAs($this->admin)->get(route('forum-polls.show', $poll))->assertOk()->assertSee('Ya');

    $this->travelTo('2026-12-01 05:00:00');
    $this->actingAs($this->admin)->put(route('forum-polls.close', $poll), ['closed' => 1])->assertSessionHasNoErrors();
    expect($poll->fresh()->phase())->toBe(ForumPoll::PHASE_ENDED);
    $this->actingAs($this->admin)->put(route('forum-polls.close', $poll), ['closed' => 0])->assertSessionHasNoErrors();
    expect($poll->fresh()->isOpen())->toBeTrue();
});

test('a voting needs an end time after the start and at least two different options', function () {
    $this->actingAs($this->admin)->post(route('forum-polls.store'), cmsPoll(['ends_at' => '']))->assertSessionHasErrors('ends_at');
    $this->actingAs($this->admin)->post(route('forum-polls.store'), cmsPoll([
        'starts_at' => '2026-12-02T09:00', 'ends_at' => '2026-12-01T09:00',
    ]))->assertSessionHasErrors('ends_at');
    $this->actingAs($this->admin)->post(route('forum-polls.store'), cmsPoll(['options' => ['Ya', ' ya ', '']]))->assertSessionHasErrors('options');
    $this->actingAs($this->admin)->post(route('forum-polls.store'), cmsPoll(['type' => 'quiz']))->assertSessionHasErrors('type');

    // Polling boleh tanpa batas waktu
    $this->actingAs($this->admin)->post(route('forum-polls.store'), cmsPoll(['type' => 'polling', 'ends_at' => '']))->assertSessionHasNoErrors();
    expect(ForumPoll::count())->toBe(1);
});

test('type and options are locked once a member has voted', function () {
    $this->actingAs($this->admin)->post(route('forum-polls.store'), cmsPoll(['type' => 'polling', 'ends_at' => '']));
    $poll = ForumPoll::firstOrFail();
    app(ForumPollService::class)->vote($poll, Member::factory()->create(['no_lambung' => 1]), $poll->options()->first()->id);

    $this->actingAs($this->admin)->put(route('forum-polls.update', $poll), cmsPoll([
        'type' => 'voting', 'title' => 'Judul baru polling', 'options' => ['A', 'B', 'C'],
    ]))->assertSessionHasNoErrors();

    $poll->refresh();
    expect($poll->title)->toBe('Judul baru polling')
        ->and($poll->type)->toBe('polling')
        ->and($poll->options()->pluck('label')->all())->toBe(['Ya', 'Tidak'])
        ->and($poll->votes_count)->toBe(1);
});

test('admins without forum-polls permission cannot manage polls', function () {
    $editor = User::factory()->create();

    $this->actingAs($editor)->get(route('forum-polls.index'))->assertForbidden();
    $this->actingAs($editor)->post(route('forum-polls.store'), cmsPoll())->assertForbidden();
    expect(ForumPoll::count())->toBe(0);
});
