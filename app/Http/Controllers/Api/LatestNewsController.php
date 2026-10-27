<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NewsCardResource;
use App\Models\News;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Berita published terbaru untuk homepage.
 * Satu query tanpa relasi (tidak N+1). Urutan: News::scopeOrderByNewsDate (Berita EV menurut tanggal berita).
 */
class LatestNewsController extends Controller
{
    private const LIMIT = 3;

    public function __invoke(): AnonymousResourceCollection
    {
        $news = News::query()
            ->select([
                'id', 'category', 'title', 'slug', 'image', 'published_at', 'created_at',
                'summary', 'source_url', 'source_name', 'source_author', 'source_published_on', // Berita EV (media lain)
            ])
            ->published()
            ->orderByNewsDate()
            ->limit(self::LIMIT)
            ->get();

        return NewsCardResource::collection($news);
    }
}
