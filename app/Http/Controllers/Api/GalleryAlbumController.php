<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\GalleryAlbumIndexRequest;
use App\Http\Resources\GalleryAlbumResource;
use App\Models\GalleryAlbum;
use App\Support\JsonSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class GalleryAlbumController extends Controller
{
    /**
     * Album galeri published, tanggal galeri terbaru dulu, dengan pencarian judul (ID/EN).
     * Foto tiap album ikut di-eager load supaya lightbox bisa langsung tampil tanpa request lagi.
     * Paginasi bernomor: query COUNT + album + foto = 3 query (tidak N+1).
     */
    public function index(GalleryAlbumIndexRequest $request): JsonResponse
    {
        $search = trim((string) $request->validated('search'));

        $albums = GalleryAlbum::query()
            ->select(['id', 'image', 'title', 'gallery_date'])
            ->published()
            ->with('photos:id,gallery_album_id,image,sort_order')
            ->when($search !== '', fn (Builder $q) => $q->where(
                fn (Builder $q) => JsonSearch::orWhereLike($q, ['title->id', 'title->en'], $search)
            ))
            ->orderByDesc('gallery_date')
            ->orderByDesc('id')
            ->paginate($request->perPage());

        return response()->json([
            'data' => GalleryAlbumResource::collection($albums->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $albums->currentPage(),
                'last_page' => $albums->lastPage(),
                'per_page' => $albums->perPage(),
                'total' => $albums->total(),
            ],
        ]);
    }
}
