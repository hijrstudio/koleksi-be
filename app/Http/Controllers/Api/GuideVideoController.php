<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ListsGuideCategories;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\GuideVideoIndexRequest;
use App\Http\Resources\GuideVideoCardResource;
use App\Models\GuideVideo;
use App\Support\JsonSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Video edukasi untuk website. Hanya video visible (video & kategorinya published).
 */
class GuideVideoController extends Controller
{
    use ListsGuideCategories;

    /**
     * List video terbaru dengan pencarian judul (ID/EN) dan filter kategori.
     * Paginasi bernomor (FE butuh total halaman): query data + COUNT + eager load kategori = 3 query.
     */
    public function index(GuideVideoIndexRequest $request): JsonResponse
    {
        $search = trim((string) $request->validated('search'));
        $categoryId = $request->validated('category');

        $videos = GuideVideo::query()
            ->select(['id', 'guide_category_id', 'title', 'thumbnail', 'youtube_id', 'created_at'])
            ->visible()
            ->with('category:id,name')
            ->when($categoryId, fn (Builder $q) => $q->where('guide_category_id', $categoryId))
            ->when($search !== '', fn (Builder $q) => $q->where(
                fn (Builder $q) => JsonSearch::orWhereLike($q, ['title->id', 'title->en'], $search)
            ))
            ->latest('created_at')
            ->latest('id')
            ->paginate($request->perPage());

        return response()->json([
            'data' => GuideVideoCardResource::collection($videos->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $videos->currentPage(),
                'last_page' => $videos->lastPage(),
                'per_page' => $videos->perPage(),
                'total' => $videos->total(),
            ],
        ]);
    }

    /**
     * Kategori untuk filter: published dan punya minimal satu video published.
     */
    public function categories(): JsonResponse
    {
        return $this->guideCategoriesHaving('videos');
    }
}
