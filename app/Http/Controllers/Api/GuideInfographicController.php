<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ListsGuideCategories;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\GuideInfographicIndexRequest;
use App\Http\Resources\GuideInfographicCardResource;
use App\Models\GuideInfographic;
use App\Support\JsonSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Infografis untuk website. Hanya infografis visible (infografis & kategorinya published).
 */
class GuideInfographicController extends Controller
{
    use ListsGuideCategories;

    /**
     * List infografis terbaru dengan pencarian judul (ID/EN) dan filter kategori.
     * Paginasi bernomor: query data + COUNT + eager load kategori = 3 query (tidak N+1).
     */
    public function index(GuideInfographicIndexRequest $request): JsonResponse
    {
        $search = trim((string) $request->validated('search'));
        $categoryId = $request->validated('category');

        $infographics = GuideInfographic::query()
            ->select(['id', 'guide_category_id', 'title', 'image', 'created_at'])
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
            'data' => GuideInfographicCardResource::collection($infographics->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $infographics->currentPage(),
                'last_page' => $infographics->lastPage(),
                'per_page' => $infographics->perPage(),
                'total' => $infographics->total(),
            ],
        ]);
    }

    /**
     * Kategori untuk filter: published dan punya minimal satu infografis published.
     */
    public function categories(): JsonResponse
    {
        return $this->guideCategoriesHaving('infographics');
    }
}
