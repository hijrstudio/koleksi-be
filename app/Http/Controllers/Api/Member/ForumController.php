<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Resources\ForumCommentResource;
use App\Http\Resources\ForumDiscussionResource;
use App\Models\ForumCategory;
use App\Models\ForumComment;
use App\Models\ForumDiscussion;
use App\Models\Member;
use App\Services\ForumService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Forum untuk member yang login (website). Diskusi yang disembunyikan / dihapus admin tidak bisa dibuka.
 *
 *   GET  /forum/categories                       kategori aktif
 *   GET  /forum/overview                         statistik sidebar + widget (trending, anggota teraktif)
 *   GET  /forum/discussions                      daftar (cari, kategori, urutan, halaman)
 *   POST /forum/discussions                      buat diskusi (multipart, gambar opsional)
 *   GET  /forum/discussions/{slug}               detail (+1 dilihat, maks sekali/hari/member)
 *   GET  /forum/discussions/{slug}/comments      komentar utama per halaman + balasannya
 *   POST /forum/discussions/{slug}/comments      komentar / balasan (parent_id)
 *   PUT  /forum/discussions/{slug}/rating        rating 1–5
 */
class ForumController extends Controller
{
    private const PER_PAGE = 10;

    private const COMMENTS_PER_PAGE = 20;

    public const IMAGE_MAX_KB = 2048;

    public function __construct(private ForumService $forum) {}

    public function categories(): JsonResponse
    {
        return response()->json([
            'data' => ForumCategory::query()->active()->ordered()->get(['id', 'slug', 'name']),
        ]);
    }

    public function overview(): JsonResponse
    {
        return response()->json(['data' => ['stats' => $this->forum->stats(), ...$this->forum->widgets()]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $params = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', Rule::in(['latest', 'oldest'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $search = trim((string) ($params['search'] ?? ''));
        $oldest = ($params['sort'] ?? 'latest') === 'oldest';

        $discussions = ForumDiscussion::query()
            ->published()
            ->with(['category:id,slug,name', 'member', 'user:id,name'])
            ->when($params['category'] ?? null, fn ($q, string $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
            ->when($search !== '', fn ($q) => $q->where('title', 'like', '%'.addcslashes($search, '%_\\').'%'))
            // Disematkan admin tampil paling atas di urutan terbaru
            ->when(! $oldest, fn ($q) => $q->orderByDesc('is_pinned'))
            ->orderBy('created_at', $oldest ? 'asc' : 'desc')
            ->orderBy('id', $oldest ? 'asc' : 'desc')
            ->simplePaginate(self::PER_PAGE);

        return ForumDiscussionResource::collection($discussions);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('forum_categories', 'id')->where('is_active', true)],
            'title' => ['required', 'string', 'min:5', 'max:'.ForumDiscussion::TITLE_MAX],
            'body' => ['required', 'string', 'min:10', 'max:'.ForumDiscussion::BODY_MAX],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::IMAGE_MAX_KB,
                'dimensions:min_width=300,min_height=150,max_width=6000,max_height=6000'],
        ], [
            'required' => ':Attribute wajib diisi.',
            'title.min' => 'Judul minimal 5 karakter.',
            'body.min' => 'Isi diskusi minimal 10 karakter.',
            'max' => ':Attribute terlalu panjang.',
            'image.max' => 'Ukuran gambar maksimal 2 MB.',
            'image.image' => 'File harus berupa gambar.',
            'image.mimes' => 'Gambar harus berupa JPG, PNG, atau WEBP.',
            'image.dimensions' => 'Ukuran gambar minimal 300×150 piksel.',
            'exists' => ':Attribute tidak valid.',
        ], ['category_id' => 'kategori', 'title' => 'judul', 'body' => 'isi diskusi', 'image' => 'gambar']);

        $discussion = $this->forum->createDiscussion($data, $this->member($request), $request->file('image'));

        return (new ForumDiscussionResource($discussion->load(['category', 'member'])))->detail()
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, string $slug): ForumDiscussionResource
    {
        $discussion = $this->find($slug);
        $this->forum->recordView($discussion, $this->member($request));

        return (new ForumDiscussionResource($discussion->load(['category', 'member', 'user:id,name'])))->detail();
    }

    /** Komentar utama (lama → baru) per halaman, masing-masing dengan seluruh balasannya (maks 3 tingkat). */
    public function comments(Request $request, string $slug): AnonymousResourceCollection
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:1000']]);
        $discussion = $this->find($slug);

        $roots = $discussion->comments()->whereNull('parent_id')
            ->with(['member', 'user:id,name'])
            ->orderBy('id')
            ->paginate(self::COMMENTS_PER_PAGE);

        // Balasan tingkat 1 & 2 dalam 2 query (bukan per komentar)
        $level1 = $this->repliesOf($roots->getCollection());
        $level2 = $this->repliesOf($level1);
        $this->attachChildren($level1, $level2);
        $this->attachChildren($roots->getCollection(), $level1);

        return ForumCommentResource::collection($roots);
    }

    public function comment(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.ForumComment::BODY_MAX],
            'parent_id' => ['nullable', 'integer'],
        ], [
            'body.required' => 'Komentar tidak boleh kosong.',
            'body.max' => 'Komentar maksimal '.ForumComment::BODY_MAX.' karakter.',
        ]);

        if (ForumService::cleanText($data['body']) === '') {
            return response()->json(['message' => 'Komentar tidak boleh kosong.', 'errors' => ['body' => ['Komentar tidak boleh kosong.']]], 422);
        }

        $comment = $this->forum->comment($this->find($slug), $this->member($request), $data['body'], $data['parent_id'] ?? null);

        return (new ForumCommentResource($comment->load(['member', 'user'])))->response()->setStatusCode(201);
    }

    public function rate(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5']]);

        return response()->json(['data' => $this->forum->rate($this->find($slug), $this->member($request), (int) $data['rating'])]);
    }

    /** Diskusi terbit; yang disembunyikan / dihapus admin = 404 (tidak membocorkan keberadaannya). */
    private function find(string $slug): ForumDiscussion
    {
        abort_unless(preg_match('/^[a-z0-9-]{1,190}$/', $slug) === 1, 404);

        return ForumDiscussion::query()->published()->where('slug', $slug)->firstOrFail();
    }

    /**
     * @param  Collection<int, ForumComment>  $parents
     * @return Collection<int, ForumComment>
     */
    private function repliesOf(Collection $parents): Collection
    {
        if ($parents->isEmpty()) {
            return new Collection;
        }

        return ForumComment::query()->whereIn('parent_id', $parents->modelKeys())
            ->with(['member', 'user:id,name'])->orderBy('id')->get();
    }

    /**
     * @param  Collection<int, ForumComment>  $parents
     * @param  Collection<int, ForumComment>  $children
     */
    private function attachChildren(Collection $parents, Collection $children): void
    {
        $grouped = $children->groupBy('parent_id');
        $parents->each(fn (ForumComment $p) => $p->setRelation('children', $grouped->get($p->id, new Collection)));
    }

    private function member(Request $request): Member
    {
        /** @var Member */
        return $request->user();
    }
}
