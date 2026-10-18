<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NewsCardResource;
use App\Models\News;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Berita published terbaru untuk homepage.
 * Satu query tanpa relasi (tidak N+1), memakai index (status, published_at).
 */
class LatestNewsController extends Controller
{
    private const LIMIT = 3;

    public function __invoke(): AnonymousResourceCollection
    {
        $news = News::query()
            ->select(['id', 'category', 'title', 'slug', 'image', 'published_at', 'created_at'])
            ->published()
            ->latest('published_at')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        return NewsCardResource::collection($news);
    }
}
