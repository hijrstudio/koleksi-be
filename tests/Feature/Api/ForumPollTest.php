<?php

use App\Http\Resources\ForumPollResource;
use App\Models\ForumPoll;
use App\Models\ForumPollOption;
use App\Models\Member;
use App\Models\User;
use App\Services\ForumPollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const POLLS = '/api/v1/member/forum/polls';

beforeEach(function () {
    Cache::flush();
    $this->admin = User::factory()->create(['name' => 'Admin KOLEKSI']);
    $this->budi = Member::factory()->create(['no_lambung' => 1]);
    $this->sari = Member::factory()->create(['no_lambung' => 2]);
});

function makePoll(User $admin, string $type, array $overrides = []): ForumPoll
{
    return app(ForumPollService::class)->save(new ForumPoll, $overrides + [
        'type' => $type,
        'title' => 'Apakah EV pilihan tepat?',
        'status' => ForumPoll::PUBLISHED,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDay(),
        'options' => ['Ya', 'Tidak', ' ya ', ''],   // dobel & kosong dibuang
    ], $admin);
}

function optionOf(ForumPoll $poll, string $label): ForumPollOption
{
    return $poll->options()->where('label', $label)->firstOrFail();
}

test('polls are only for logged-in members', function () {
    $this->getJson(POLLS.'?type=voting')->assertUnauthorized();
    $this->postJson(POLLS.'/1/vote')->assertUnauthorized();
});

test('a running voting hides results, accepts one vote and refuses a change', function () {
    $poll = makePoll($this->admin, ForumPoll::VOTING);
    expect($poll->options()->pluck('label')->all())->toBe(['Ya', 'Tidak']);
    Sanctum::actingAs($this->budi, ['member']);

    $this->postJson(POLLS."/{$poll->id}/vote", ['option_id' => optionOf($poll, 'Ya')->id])->assertOk()
        ->assertJsonPath('data.votes_count', 1)
        ->assertJsonPath('data.my_option_id', optionOf($poll, 'Ya')->id)
        ->assertJsonPath('data.results_visible', false)
        ->assertJsonMissingPath('data.options.0.votes')
        ->assertJsonMissingPath('data.options.0.percent');

    $this->postJson(POLLS."/{$poll->id}/vote", ['option_id' => optionOf($poll, 'Tidak')->id])->assertUnprocessable()
        ->assertJsonValidationErrors('option_id');
    $this->postJson(POLLS."/{$poll->id}/vote", ['option_id' => optionOf($poll, 'Ya')->id])->assertUnprocessable();

    expect($poll->fresh()->votes_count)->toBe(1)
        ->and(optionOf($poll, 'Ya')->votes_count)->toBe(1)
        ->and(optionOf($poll, 'Tidak')->votes_count)->toBe(0);

    // Hasil terbuka setelah voting selesai, tidak bisa memilih lagi
    $this->travel(2)->days();
    Sanctum::actingAs($this->sari, ['member']);
    $this->getJson(POLLS.'?type=voting')->assertOk()
        ->assertJsonPath('data.0.phase', 'ended')
        ->assertJsonPath('data.0.results_visible', true)
        ->assertJsonPath('data.0.options.0.percent', 100)
        ->assertJsonPath('data.0.my_option_id', null);
    $this->postJson(POLLS."/{$poll->id}/vote", ['option_id' => optionOf($poll, 'Tidak')->id])->assertUnprocessable();
});

test('a polling shows live results and lets a member change the choice, keeping counters right', function () {
    $poll = makePoll($this->admin, ForumPoll::POLLING, ['ends_at' => null]);
    $ya = optionOf($poll, 'Ya');
    $tidak = optionOf($poll, 'Tidak');

    Sanctum::actingAs($this->sari, ['member']);
    $this->postJson(POLLS."/{$poll->id}/vote", ['option_id' => $tidak->id])->assertOk();

    Sanctum::actingAs($this->budi, ['member']);
    $this->postJson(POLLS."/{$poll->id}/vote", ['option_id' => $ya->id])->assertOk()
        ->assertJsonPath('data.results_visible', true)
        ->assertJsonPath('data.options.0.percent', 50);
    $this->postJson(POLLS."/{$poll->id}/vote", ['option_id' => $tidak->id])->assertOk()
        ->assertJsonPath('data.my_option_id', $tidak->id)
        ->assertJsonPath('data.votes_count', 2)
        ->assertJsonPath('data.options.0.votes', 0)
        ->assertJsonPath('data.options.1.votes', 2)
        ->assertJsonPath('data.options.1.percent', 100);

    expect($poll->votes()->count())->toBe(2);
});

test('an option of another poll, a hidden, scheduled, closed or deleted poll cannot be voted', function () {
    $poll = makePoll($this->admin, ForumPoll::POLLING);
    $other = makePoll($this->admin, ForumPoll::POLLING);
    Sanctum::actingAs($this->budi, ['member']);

    $this->postJson(POLLS."/{$poll->id}/vote", ['option_id' => optionOf($other, 'Ya')->id])->assertUnprocessable();
    $this->postJson(POLLS."/{$poll->id}/vote", [])->assertUnprocessable();

    $hidden = makePoll($this->admin, ForumPoll::POLLING, ['status' => ForumPoll::HIDDEN]);
    $scheduled = makePoll($this->admin, ForumPoll::POLLING, ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
    $deleted = makePoll($this->admin, ForumPoll::POLLING);
    $deleted->delete();
    foreach ([$hidden, $scheduled, $deleted] as $p) {
        $this->postJson(POLLS."/{$p->id}/vote", ['option_id' => optionOf($p, 'Ya')->id])->assertNotFound();
    }

    app(ForumPollService::class)->setClosed($poll, true);
    $this->postJson(POLLS."/{$poll->id}/vote", ['option_id' => optionOf($poll, 'Ya')->id])->assertUnprocessable();

    // Daftar hanya berisi yang tampil & sudah mulai
    $ids = collect($this->getJson(POLLS.'?type=polling')->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toContain($poll->id, $other->id)->not->toContain($hidden->id, $scheduled->id, $deleted->id);
});

test('the list puts running polls first, filters by type and searches the title', function () {
    $ended = makePoll($this->admin, ForumPoll::VOTING, ['title' => 'Merek EV favorit', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);
    $running = makePoll($this->admin, ForumPoll::VOTING, ['title' => 'Charging di mana?', 'starts_at' => now()->subDays(5)]);
    makePoll($this->admin, ForumPoll::POLLING, ['title' => 'Charging polling']);
    Sanctum::actingAs($this->budi, ['member']);

    $this->getJson(POLLS.'?type=voting')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $running->id)
        ->assertJsonPath('data.1.id', $ended->id)
        ->assertJsonPath('data.0.author.role', 'Moderator');
    $this->getJson(POLLS.'?type=voting&search=charging')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(POLLS)->assertUnprocessable();
});

test('percentages always add up to 100', function () {
    $poll = makePoll($this->admin, ForumPoll::POLLING, ['options' => ['A', 'B', 'C']]);
    $poll->options->each(fn ($o) => $o->forceFill(['votes_count' => 1])->save());

    expect(array_sum(ForumPollResource::percents($poll->fresh()->options)))->toBe(100);
});
