<?php

namespace App\Http\Controllers\Api;

use App\Enums\NewsCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\NewsCardResource;
use App\Models\News;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Berita untuk homepage: maks. 3 kartu dengan urutan tetap, masing-masing diberi `slot`:
 * - `news_release`: News Release terbaru
 * - `latest`: Berita EV terbaru (menurut tanggal berita)
 * - `archive`: Berita EV terlama (dilewati kalau sama dengan `latest`, yaitu baru ada 1 Berita EV)
 *
 * Slot yang belum punya berita published dilewati. 3 query tanpa relasi (tidak N+1).
 * Bentuk item = NewsCardResource + `slot`.
 */
class LatestNewsController extends Controller
{
    private const COLUMNS = [
        'id', 'category', 'title', 'slug', 'image', 'published_at', 'created_at',
        'summary', 'source_url', 'source_name', 'source_author', 'source_published_on', // Berita EV (media lain)
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $newsRelease = $this->first(NewsCategory::NewsRelease, 'desc');
        $latestEv = $this->first(NewsCategory::EvNews, 'desc');
        $oldestEv = $latestEv ? $this->first(NewsCategory::EvNews, 'asc') : null;

        if ($oldestEv?->is($latestEv)) {
            $oldestEv = null;
        }

        $items = collect(['news_release' => $newsRelease, 'latest' => $latestEv, 'archive' => $oldestEv])
            ->filter()
            ->map(fn (News $news, string $slot) => ['slot' => $slot, ...(new NewsCardResource($news))->resolve($request)])
            ->values();

        return response()->json(['data' => $items]);
    }

    private function first(NewsCategory $category, string $direction): ?News
    {
        return News::query()
            ->select(self::COLUMNS)
            ->published()
            ->where('category', $category)
            ->orderByNewsDate($direction)
            ->first();
    }
}
