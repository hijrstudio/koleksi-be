<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberBookmark;
use App\Models\MemberPointTotal;
use App\Services\PointService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Poin papan peringkat untuk member yang login. Server yang menentukan poin (config/points.php):
 * website hanya memberi tahu halaman yang dibuka / artikel yang dibaca.
 *
 *   POST /points/visit        {path}         kunjungan harian + menu (sekali per menu per hari)
 *   POST /points/read/start   {type, slug}   mulai baca artikel / berita (dicatat waktu server)
 *   POST /points/read/claim   {type, slug}   klaim poin baca (>= read_seconds sejak start, sekali per konten)
 *   GET  /forum/leaderboard?period=month|all
 */
class PointController extends Controller
{
    private const LEADERBOARD_SIZE = 10;

    private const READ_TYPES = ['news', 'article'];

    public function __construct(private PointService $points) {}

    public function visit(Request $request): JsonResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:300']]);

        return response()->json(['data' => ['earned' => $this->points->visit($this->member($request), $data['path'])]]);
    }

    public function startReading(Request $request): JsonResponse
    {
        $this->points->startReading($this->member($request), $this->readRef($request));

        return response()->json(['data' => ['seconds' => (int) config('points.read_seconds')]]);
    }

    public function claimReading(Request $request): JsonResponse
    {
        $earned = $this->points->claimReading($this->member($request), $this->readRef($request));

        return response()->json(['data' => ['earned' => $earned ? (int) config('points.actions.read.points') : 0]]);
    }

    public function leaderboard(Request $request): JsonResponse
    {
        $params = $request->validate(['period' => ['nullable', Rule::in(['month', 'all'])]]);
        $period = ($params['period'] ?? 'month') === 'all' ? MemberPointTotal::ALL : PointService::monthPeriod($this->points->now());

        // Daftar teratas sama untuk semua member: cache singkat
        $top = Cache::remember("points:leaderboard:{$period}", now()->addMinute(), fn () => $this->ranked($period)
            ->limit(self::LEADERBOARD_SIZE)->get()
            ->values()
            ->map(fn (MemberPointTotal $row, int $i) => [
                'rank' => $i + 1,
                'name' => $row->member->nama_panggilan ?: $row->member->nama_lengkap,
                'avatar' => $row->member->photo_url,
                'city' => $row->member->city?->name,
                'points' => $row->points,
                'member_id' => $row->member_id,   // hanya untuk menandai "Anda"; dibuang sebelum dikirim
            ])->all());

        $me = $this->member($request);
        $mine = MemberPointTotal::query()->where('member_id', $me->id)->where('period', $period)->where('points', '>', 0)->first();

        return response()->json([
            'data' => array_map(fn (array $row) => [
                ...array_diff_key($row, ['member_id' => true]),
                'is_me' => $row['member_id'] === $me->id,
            ], $top),
            'me' => $mine ? ['rank' => $this->rankOf($mine), 'points' => $mine->points] : null,
            'period' => $period === MemberPointTotal::ALL ? 'all' : 'month',
            // Aturan poin untuk "Cara dapat poin" di website (selalu sama dengan config/points.php)
            'rules' => collect((array) config('points.actions'))
                ->map(fn (array $rule, string $action) => ['action' => $action, 'points' => $rule['points'], 'daily_cap' => $rule['daily_cap']])
                ->values(),
        ]);
    }

    /** Peringkat: poin terbanyak; seri → yang lebih dulu mencapainya. Hanya anggota resmi yang belum dihapus. */
    private function ranked(string $period)
    {
        return MemberPointTotal::query()
            ->where('period', $period)
            ->where('points', '>', 0)
            ->whereHas('member', fn ($q) => $q->registered())
            ->with(['member:id,nama_lengkap,nama_panggilan,photo_profile,city_code', 'member.city:code,name'])
            ->orderByDesc('points')
            ->orderBy('reached_at')
            ->orderBy('member_id');
    }

    private function rankOf(MemberPointTotal $mine): int
    {
        return MemberPointTotal::query()
            ->where('period', $mine->period)
            ->whereHas('member', fn ($q) => $q->registered())
            ->where(fn ($q) => $q->where('points', '>', $mine->points)
                ->orWhere(fn ($w) => $w->where('points', $mine->points)->where(fn ($r) => $r->where('reached_at', '<', $mine->reached_at)
                    ->orWhere(fn ($x) => $x->where('reached_at', $mine->reached_at)->where('member_id', '<', $mine->member_id)))))
            ->count() + 1;
    }

    /** "news:45" / "article:12" dari konten yang tampil di website; selain itu 404. */
    private function readRef(Request $request): string
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(self::READ_TYPES)],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/'],
        ]);
        $id = MemberBookmark::contentQuery($data['type'])->where('slug', $data['slug'])->value('id');
        abort_if($id === null, 404);

        return "{$data['type']}:{$id}";
    }

    private function member(Request $request): Member
    {
        /** @var Member */
        return $request->user();
    }
}
