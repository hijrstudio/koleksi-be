<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Resources\ForumPollResource;
use App\Models\ForumPoll;
use App\Models\ForumPollVote;
use App\Models\Member;
use App\Services\ForumPollService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Polling & voting untuk member yang login. Yang dijadwalkan (belum mulai), disembunyikan, atau dihapus admin
 * tidak tampil dan tidak bisa dipilih (404).
 *
 *   GET  /forum/polls?type=voting|polling&search=&page=   yang berjalan dulu, lalu yang sudah selesai
 *   POST /forum/polls/{id}/vote   {option_id}             pilih / ganti pilihan (polling saja)
 */
class ForumPollController extends Controller
{
    private const PER_PAGE = 10;

    public function __construct(private ForumPollService $polls) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $params = $request->validate([
            'type' => ['required', Rule::in(ForumPoll::TYPES)],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $search = trim((string) ($params['search'] ?? ''));

        $polls = ForumPoll::query()
            ->visible()
            ->where('type', $params['type'])
            ->with(['options', 'user:id,name'])
            ->when($search !== '', fn ($q) => $q->where('title', 'like', '%'.addcslashes($search, '%_\\').'%'))
            // Masih berjalan di atas, lalu yang terbaru
            ->orderByRaw('CASE WHEN closed_at IS NOT NULL OR (ends_at IS NOT NULL AND ends_at <= ?) THEN 1 ELSE 0 END', [now()])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $this->attachMyVotes($polls->getCollection(), $this->member($request));

        return ForumPollResource::collection($polls);
    }

    public function vote(Request $request, int $poll): ForumPollResource
    {
        $data = $request->validate(['option_id' => ['required', 'integer']], ['option_id.required' => 'Pilih salah satu opsi.']);

        $model = ForumPoll::query()->visible()->findOrFail($poll);
        $member = $this->member($request);
        $this->polls->vote($model, $member, (int) $data['option_id']);

        $fresh = $model->fresh(['options', 'user:id,name']);
        $this->attachMyVotes(new Collection([$fresh]), $member);

        return new ForumPollResource($fresh);
    }

    /** Pilihan member untuk satu halaman polling: satu query. @param  Collection<int, ForumPoll>  $polls */
    private function attachMyVotes(Collection $polls, Member $member): void
    {
        $mine = ForumPollVote::query()->where('member_id', $member->id)
            ->whereIn('poll_id', $polls->modelKeys())->pluck('option_id', 'poll_id');

        $polls->each(fn (ForumPoll $p) => $p->setAttribute('my_option_id', $mine->get($p->id)));
    }

    private function member(Request $request): Member
    {
        /** @var Member */
        return $request->user();
    }
}
