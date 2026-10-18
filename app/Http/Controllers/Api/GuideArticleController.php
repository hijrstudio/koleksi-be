<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ListsGuideCategories;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\GuideArticleIndexRequest;
use App\Http\Resources\GuideArticleCardResource;
use App\Models\GuideArticle;
use App\Support\JsonSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Artikel panduan untuk website. Semua query hanya mengambil artikel visible
 * (artikel & kategorinya published). Relasi selalu di-eager load (tidak N+1).
 */
class GuideArticleController extends Controller
{
    use ListsGuideCategories;

    private const POPULAR_LIMIT = 5;

    private const RELATED_LIMIT = 3;

    private const CARD_COLUMNS = [
        'id', 'guide_category_id', 'title', 'slug', 'image',
        'author_member_id', 'author_user_id', 'published_at',
    ];

    /**
     * List artikel: pencarian (judul ID/EN & nama penulis), filter kategori, urut terbaru/terlama,
     * "muat lebih banyak" per halaman. simplePaginate: tanpa query COUNT, cukup tahu ada halaman berikutnya.
     */
    public function index(GuideArticleIndexRequest $request): JsonResponse
    {
        $direction = $request->sortDirection();
        $search = trim((string) $request->validated('search'));
        $categoryId = $request->validated('category');

        $articles = $this->cardQuery()
            ->when($categoryId, fn (Builder $q) => $q->where('guide_category_id', $categoryId))
            ->when($search !== '', function (Builder $q) use ($search) {
                $like = JsonSearch::likePattern($search);

                $q->where(function (Builder $q) use ($search, $like) {
                    JsonSearch::orWhereLike($q, ['title->id', 'title->en'], $search);

                    $q->orWhereHas('authorMember', fn (Builder $m) => $m->where('nama_lengkap', 'like', $like))
                        ->orWhereHas('authorUser', fn (Builder $u) => $u->where('name', 'like', $like));
                });
            })
            ->orderBy('published_at', $direction)
            ->orderBy('id', $direction)
            ->simplePaginate($request->perPage());

        return response()->json([
            'data' => GuideArticleCardResource::collection($articles->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $articles->currentPage(),
                'per_page' => $articles->perPage(),
                'has_more' => $articles->hasMorePages(),
            ],
        ]);
    }

    /**
     * Kategori untuk filter: published dan punya minimal satu artikel published.
     */
    public function categories(): JsonResponse
    {
        return $this->guideCategoriesHaving('articles');
    }

    /**
     * 5 artikel dengan views terbanyak (seri: yang terbaru dulu).
     */
    public function popular(): JsonResponse
    {
        $articles = GuideArticle::query()
            ->select(['id', 'title', 'slug', 'published_at', 'views'])
            ->visible()
            ->orderByDesc('views')
            ->orderByDesc('published_at')
            ->limit(self::POPULAR_LIMIT)
            ->get();

        return response()->json([
            'data' => $articles->map(fn (GuideArticle $article) => [
                'id' => $article->id,
                'title' => $this->localized($article->title),
                'slug' => $article->slug,
                'views' => $article->views,
                'published_at' => $article->published_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Detail artikel + artikel terkait (kategori sama, terbaru).
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $article = $this->cardQuery()
            ->addSelect('content')
            ->where('slug', $slug)
            ->firstOrFail();

        $related = $this->cardQuery()
            ->where('guide_category_id', $article->guide_category_id)
            ->whereKeyNot($article->id)
            ->latest('published_at')
            ->latest('id')
            ->limit(self::RELATED_LIMIT)
            ->get();

        return response()->json([
            'data' => [
                ...(new GuideArticleCardResource($article))->resolve($request),
                'content' => $this->localized($article->content), // HTML tersanitasi
            ],
            'related' => GuideArticleCardResource::collection($related)->resolve($request),
        ]);
    }

    /**
     * Tambah 1 view saat halaman detail dibuka. Satu UPDATE atomik (aman dari race condition),
     * lewat query builder supaya updated_at artikel tidak ikut berubah.
     */
    public function view(string $slug): JsonResponse
    {
        $updated = GuideArticle::query()
            ->visible()
            ->where('slug', $slug)
            ->toBase()
            ->increment('views');

        abort_if($updated === 0, 404);

        return response()->json([
            'data' => ['views' => (int) GuideArticle::query()->where('slug', $slug)->value('views')],
        ]);
    }

    /**
     * @return Builder<GuideArticle>
     */
    private function cardQuery(): Builder
    {
        return GuideArticle::query()
            ->select(self::CARD_COLUMNS)
            ->visible()
            ->with([
                'category:id,name',
                'authorMember:id,nama_lengkap,no_lambung',
                'authorUser:id,name',
            ]);
    }

    /**
     * @param  array<string, ?string>|null  $value
     * @return array{id: string, en: string}
     */
    private function localized(?array $value): array
    {
        $id = $value['id'] ?? '';

        return ['id' => $id, 'en' => ($value['en'] ?? null) ?: $id];
    }
}
