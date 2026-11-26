<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Api\Member\ForumController;
use App\Http\Controllers\Controller;
use App\Models\ForumCategory;
use App\Models\ForumComment;
use App\Models\ForumDiscussion;
use App\Services\ForumService;
use App\Services\PointService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Diskusi forum di CMS: admin membuat diskusi (tampil sebagai Moderator), mengedit, menyembunyikan, menyematkan,
 * mengunci komentar, menghapus (soft delete), dan memoderasi komentar (sembunyikan / hapus / balas).
 */
class ForumDiscussionController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(private ForumService $forum, private PointService $points) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ForumDiscussion::class);

        $params = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(ForumDiscussion::STATUSES)],
            'category' => ['nullable', 'integer'],
        ]);
        $search = trim((string) ($params['search'] ?? ''));

        $discussions = ForumDiscussion::query()
            ->with(['category:id,name', 'member', 'user:id,name'])
            ->when($search !== '', fn ($q) => $q->where('title', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when($params['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($params['category'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.forum-discussions.index', [
            'title' => 'Diskusi Forum',
            'discussions' => $discussions,
            'categories' => ForumCategory::query()->ordered()->get(['id', 'name']),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', ForumDiscussion::class);

        return $this->form(new ForumDiscussion(['status' => ForumDiscussion::PUBLISHED]));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ForumDiscussion::class);

        $data = $this->validated($request);
        $discussion = $this->forum->createDiscussion($data, $request->user(), $request->file('image'));
        $discussion->forceFill(array_intersect_key($data, array_flip(['status', 'is_pinned', 'is_locked'])))->save();

        return redirect()->route('forum-discussions.show', $discussion)->with('success', 'Diskusi ditambahkan.');
    }

    public function show(ForumDiscussion $forumDiscussion): View
    {
        Gate::authorize('view', $forumDiscussion);

        $comments = $forumDiscussion->comments()->with(['member', 'user:id,name'])->orderBy('id')->get();

        return view('pages.cms.forum-discussions.show', [
            'title' => 'Detail Diskusi',
            'discussion' => $forumDiscussion->load(['category:id,name', 'member', 'user:id,name']),
            // Pohon komentar: induk → balasan (maks 3 tingkat, disusun di view)
            'commentsByParent' => $comments->groupBy(fn (ForumComment $c) => $c->parent_id ?? 0),
        ]);
    }

    public function edit(ForumDiscussion $forumDiscussion): View
    {
        Gate::authorize('update', $forumDiscussion);

        return $this->form($forumDiscussion);
    }

    public function update(Request $request, ForumDiscussion $forumDiscussion): RedirectResponse
    {
        Gate::authorize('update', $forumDiscussion);

        $data = $this->validated($request);
        $forumDiscussion->fill([
            'category_id' => $data['category_id'],
            'title' => ForumService::cleanLine($data['title']),
            'body' => ForumService::cleanText($data['body']),
            'status' => $data['status'],
            'is_pinned' => $data['is_pinned'],
            'is_locked' => $data['is_locked'],
        ])->save();
        $this->forum->replaceImage($forumDiscussion, $request->file('image'), $request->boolean('remove_image'));
        $this->forum->forgetStats();
        // Disembunyikan → poin penulis ditarik; ditampilkan lagi → dikembalikan
        $forumDiscussion->isPublished()
            ? $this->points->restore('discussion', "discussion:{$forumDiscussion->id}")
            : $this->points->revoke('discussion', "discussion:{$forumDiscussion->id}");

        return redirect()->route('forum-discussions.show', $forumDiscussion)->with('success', 'Diskusi diperbarui.');
    }

    public function destroy(ForumDiscussion $forumDiscussion): RedirectResponse
    {
        Gate::authorize('delete', $forumDiscussion);

        $forumDiscussion->delete();   // soft delete: hilang dari website, data tetap tersimpan
        $this->forum->forgetStats();
        $this->points->revoke('discussion', "discussion:{$forumDiscussion->id}");

        return redirect()->route('forum-discussions.index')->with('success', 'Diskusi dihapus.');
    }

    /** Admin berkomentar / membalas (tampil sebagai Moderator). Diskusi terkunci tetap bisa dikomentari admin. */
    public function comment(Request $request, ForumDiscussion $forumDiscussion): RedirectResponse
    {
        Gate::authorize('update', $forumDiscussion);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.ForumComment::BODY_MAX],
            'parent_id' => ['nullable', 'integer'],
        ], [], ['body' => 'komentar']);

        $this->forum->comment($forumDiscussion, $request->user(), $data['body'], $data['parent_id'] ?? null);

        return redirect()->route('forum-discussions.show', $forumDiscussion)->with('success', 'Komentar dikirim.');
    }

    /** Sembunyikan / tampilkan lagi komentar (balasannya tetap tampil di bawah penanda "disembunyikan"). */
    public function toggleComment(ForumComment $forumComment): RedirectResponse
    {
        $discussion = $forumComment->discussion()->withTrashed()->firstOrFail();
        Gate::authorize('update', $discussion);

        $forumComment->update(['status' => $forumComment->status === ForumComment::PUBLISHED ? ForumComment::HIDDEN : ForumComment::PUBLISHED]);
        $this->forum->refreshCommentCount($discussion);
        $forumComment->status === ForumComment::HIDDEN
            ? $this->points->revoke('comment', "comment:{$forumComment->id}")
            : $this->points->restore('comment', "comment:{$forumComment->id}");

        return back()->with('success', $forumComment->status === ForumComment::HIDDEN ? 'Komentar disembunyikan.' : 'Komentar ditampilkan lagi.');
    }

    /** Hapus komentar beserta seluruh balasannya (soft delete). */
    public function destroyComment(ForumComment $forumComment): RedirectResponse
    {
        $discussion = $forumComment->discussion()->withTrashed()->firstOrFail();
        Gate::authorize('update', $discussion);

        DB::transaction(function () use ($forumComment) {
            $ids = [$forumComment->id];
            for ($level = $ids; $level !== [];) {
                $level = ForumComment::query()->whereIn('parent_id', $level)->pluck('id')->all();
                $ids = [...$ids, ...$level];
            }
            ForumComment::query()->whereIn('id', $ids)->delete();
            $this->points->revokeMany('comment', array_map(fn (int $id) => "comment:{$id}", $ids));
        });
        $this->forum->refreshCommentCount($discussion);

        return back()->with('success', 'Komentar beserta balasannya dihapus.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('forum_categories', 'id')],
            'title' => ['required', 'string', 'min:5', 'max:'.ForumDiscussion::TITLE_MAX],
            'body' => ['required', 'string', 'min:10', 'max:'.ForumDiscussion::BODY_MAX],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ForumController::IMAGE_MAX_KB,
                'dimensions:min_width=300,min_height=150,max_width=6000,max_height=6000'],
            'remove_image' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in(ForumDiscussion::STATUSES)],
            'is_pinned' => ['required', 'boolean'],
            'is_locked' => ['required', 'boolean'],
        ], ['image.max' => 'Ukuran gambar maksimal 2 MB.', 'image.dimensions' => 'Ukuran gambar minimal 300×150 piksel.'],
            ['category_id' => 'kategori', 'title' => 'judul', 'body' => 'isi diskusi', 'image' => 'gambar']);

        return [...$data, 'is_pinned' => (bool) $data['is_pinned'], 'is_locked' => (bool) $data['is_locked']];
    }

    private function form(ForumDiscussion $discussion): View
    {
        return view('pages.cms.forum-discussions.form', [
            'title' => $discussion->exists ? 'Edit Diskusi' : 'Tambah Diskusi',
            'discussion' => $discussion,
            'categories' => ForumCategory::query()->ordered()->get(['id', 'name', 'is_active']),
        ]);
    }
}
