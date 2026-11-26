<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Http\Resources\ForumPollResource;
use App\Models\ForumPoll;
use App\Services\ForumPollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Polling & voting di CMS. Admin membuat (tampil sebagai Moderator), mengedit, melihat hasil kapan saja,
 * menutup lebih awal / membuka lagi, menyembunyikan, dan menghapus (soft delete).
 * Jenis & opsi dikunci begitu ada suara pertama. Waktu di form = WIB.
 */
class ForumPollController extends Controller
{
    private const PER_PAGE = 15;

    public const TZ = 'Asia/Jakarta';

    private const INPUT_FORMAT = 'Y-m-d\TH:i';

    public function __construct(private ForumPollService $polls) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ForumPoll::class);

        $params = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(ForumPoll::TYPES)],
            'status' => ['nullable', Rule::in(ForumPoll::STATUSES)],
        ]);
        $search = trim((string) ($params['search'] ?? ''));

        $polls = ForumPoll::query()
            ->withCount('options')
            ->with('user:id,name')
            ->when($search !== '', fn ($q) => $q->where('title', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when($params['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($params['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.forum-polls.index', ['title' => 'Polling & Voting', 'polls' => $polls]);
    }

    public function create(): View
    {
        Gate::authorize('create', ForumPoll::class);

        return $this->form(new ForumPoll(['type' => ForumPoll::VOTING, 'status' => ForumPoll::PUBLISHED]));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ForumPoll::class);

        $poll = $this->polls->save(new ForumPoll, $this->validated($request, null), $request->user());

        return redirect()->route('forum-polls.show', $poll)->with('success', 'Polling ditambahkan.');
    }

    /** Hasil lengkap (termasuk voting yang masih berjalan; hanya untuk admin). */
    public function show(ForumPoll $forumPoll): View
    {
        Gate::authorize('view', $forumPoll);

        $forumPoll->load(['options', 'user:id,name']);

        return view('pages.cms.forum-polls.show', [
            'title' => 'Hasil Polling',
            'poll' => $forumPoll,
            'percents' => ForumPollResource::percents($forumPoll->options),
        ]);
    }

    public function edit(ForumPoll $forumPoll): View
    {
        Gate::authorize('update', $forumPoll);

        return $this->form($forumPoll->load('options'));
    }

    public function update(Request $request, ForumPoll $forumPoll): RedirectResponse
    {
        Gate::authorize('update', $forumPoll);

        $this->polls->save($forumPoll, $this->validated($request, $forumPoll), $request->user());

        return redirect()->route('forum-polls.show', $forumPoll)->with('success', 'Polling diperbarui.');
    }

    public function destroy(ForumPoll $forumPoll): RedirectResponse
    {
        Gate::authorize('delete', $forumPoll);

        $forumPoll->delete();   // soft delete: hilang dari website, suara tetap tersimpan

        return redirect()->route('forum-polls.index')->with('success', 'Polling dihapus.');
    }

    /** Tutup sekarang / buka lagi. */
    public function close(Request $request, ForumPoll $forumPoll): RedirectResponse
    {
        Gate::authorize('update', $forumPoll);

        $closed = $request->validate(['closed' => ['required', 'boolean']])['closed'];
        $this->polls->setClosed($forumPoll, (bool) $closed);

        return back()->with('success', $closed ? 'Polling ditutup.' : 'Polling dibuka lagi.');
    }

    /** @return array{type: string, title: string, status: string, starts_at: Carbon, ends_at: ?Carbon, options?: list<string>} */
    private function validated(Request $request, ?ForumPoll $poll): array
    {
        $locked = $poll?->optionsLocked() ?? false;

        $data = $request->validate([
            'type' => [$locked ? 'nullable' : 'required', Rule::in(ForumPoll::TYPES)],
            'title' => ['required', 'string', 'min:5', 'max:'.ForumPoll::TITLE_MAX],
            'status' => ['required', Rule::in(ForumPoll::STATUSES)],
            'starts_at' => ['nullable', 'date_format:'.self::INPUT_FORMAT],
            'ends_at' => ['nullable', 'date_format:'.self::INPUT_FORMAT],
            ...($locked ? [] : [
                'options' => ['required', 'array', 'max:'.ForumPoll::MAX_OPTIONS],
                'options.*' => ['nullable', 'string', 'max:'.ForumPoll::OPTION_MAX],
            ]),
        ], [
            'date_format' => 'Format :attribute tidak valid.',
            'options.max' => 'Maksimal '.ForumPoll::MAX_OPTIONS.' opsi.',
            'options.*.max' => 'Opsi maksimal '.ForumPoll::OPTION_MAX.' karakter.',
        ], ['type' => 'jenis', 'title' => 'judul', 'starts_at' => 'waktu mulai', 'ends_at' => 'waktu selesai', 'options' => 'opsi']);

        $type = $locked ? $poll->type : $data['type'];
        $startsAt = $this->parse($data['starts_at'] ?? null) ?? ($poll?->starts_at ?? now());
        $endsAt = $this->parse($data['ends_at'] ?? null);

        $errors = [];
        if ($type === ForumPoll::VOTING && $endsAt === null) {
            $errors['ends_at'] = 'Voting wajib punya waktu selesai.';
        } elseif ($endsAt !== null && $endsAt->lte($startsAt)) {
            $errors['ends_at'] = 'Waktu selesai harus setelah waktu mulai.';
        }
        if (! $locked && count(ForumPollService::cleanOptions($data['options'])) < ForumPoll::MIN_OPTIONS) {
            $errors['options'] = 'Isi minimal '.ForumPoll::MIN_OPTIONS.' opsi yang berbeda.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'type' => $type,
            'title' => $data['title'],
            'status' => $data['status'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            ...($locked ? [] : ['options' => array_values($data['options'])]),
        ];
    }

    /** "2026-12-01T09:00" (WIB) → waktu aplikasi (UTC). */
    private function parse(?string $value): ?Carbon
    {
        return $value ? Carbon::createFromFormat('!'.self::INPUT_FORMAT, $value, self::TZ)->setTimezone(config('app.timezone')) : null;
    }

    private function form(ForumPoll $poll): View
    {
        return view('pages.cms.forum-polls.form', [
            'title' => $poll->exists ? 'Edit Polling' : 'Tambah Polling',
            'poll' => $poll,
            'locked' => $poll->exists && $poll->optionsLocked(),
        ]);
    }
}
