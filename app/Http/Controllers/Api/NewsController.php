<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NewsIndexRequest;
use App\Http\Resources\NewsCardResource;
use App\Models\News;
use App\Support\JsonSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Berita untuk website, hanya yang published. Penulis selalu di-eager load (tidak N+1).
 */
class NewsController extends Controller
{
    private const RELATED_LIMIT = 3;

    private const CARD_COLUMNS = [
        'id', 'category', 'title', 'slug', 'image',
        'author_member_id', 'author_user_id', 'published_at', 'created_at',
    ];

    /**
     * List berita: pencarian (judul ID/EN & nama penulis), filter kategori, urut terbaru/terlama.
     * Paginasi bernomor: COUNT + berita + 2 eager load penulis = maks. 4 query.
     */
    public function index(NewsIndexRequest $request): JsonResponse
    {
        $direction = $request->sortDirection();
        $category = $request->category();
        $search = trim((string) $request->validated('search'));

        $news = $this->cardQuery()
            ->when($category, fn (Builder $q) => $q->where('category', $category))
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
            ->paginate($request->perPage());

        return response()->json([
            'data' => NewsCardResource::collection($news->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $news->currentPage(),
                'last_page' => $news->lastPage(),
                'per_page' => $news->perPage(),
                'total' => $news->total(),
            ],
        ]);
    }

    /**
     * Detail berita + berita terkait (kategori sama, terbaru).
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $news = $this->cardQuery()
            ->addSelect('content')
            ->where('slug', $slug)
            ->firstOrFail();

        $related = $this->cardQuery()
            ->where('category', $news->category)
            ->whereKeyNot($news->id)
            ->latest('published_at')
            ->latest('id')
            ->limit(self::RELATED_LIMIT)
            ->get();

        $content = $news->content ?? [];

        return response()->json([
            'data' => [
                ...(new NewsCardResource($news))->resolve($request),
                'content' => [ // HTML tersanitasi
                    'id' => $content['id'] ?? '',
                    'en' => ($content['en'] ?? null) ?: ($content['id'] ?? ''),
                ],
            ],
            'related' => NewsCardResource::collection($related)->resolve($request),
        ]);
    }

    /**
     * @return Builder<News>
     */
    private function cardQuery(): Builder
    {
        return News::query()
            ->select(self::CARD_COLUMNS)
            ->published()
            ->with([
                'authorMember:id,nama_lengkap,no_lambung',
                'authorUser:id,name',
            ]);
    }
}
