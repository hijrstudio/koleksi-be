<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EventVideoIndexRequest;
use App\Http\Resources\EventVideoCardResource;
use App\Models\EventVideo;
use App\Support\JsonSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Video kegiatan untuk website. Hanya video published.
 */
class EventVideoController extends Controller
{
    /**
     * List video terbaru dengan pencarian judul (ID/EN).
     * Paginasi bernomor (FE butuh total halaman): query data + COUNT = 2 query.
     */
    public function index(EventVideoIndexRequest $request): JsonResponse
    {
        $search = trim((string) $request->validated('search'));

        $videos = EventVideo::query()
            ->select(['id', 'title', 'thumbnail', 'youtube_id', 'created_at'])
            ->published()
            ->when($search !== '', fn (Builder $q) => $q->where(
                fn (Builder $q) => JsonSearch::orWhereLike($q, ['title->id', 'title->en'], $search)
            ))
            ->latest('created_at')
            ->latest('id')
            ->paginate($request->perPage());

        return response()->json([
            'data' => EventVideoCardResource::collection($videos->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $videos->currentPage(),
                'last_page' => $videos->lastPage(),
                'per_page' => $videos->perPage(),
                'total' => $videos->total(),
            ],
        ]);
    }
}
