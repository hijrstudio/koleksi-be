<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GuideArticleCardResource;
use App\Http\Resources\GuideVideoCardResource;
use App\Models\GuideArticle;
use App\Models\GuideVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panduan praktis terbaru untuk homepage: N artikel + N video, urutan bergantian
 * (artikel, video, artikel, ...). Kalau salah satu kurang, sisanya ditaruh di belakang.
 *
 * Jumlah query tetap (tidak N+1): 1 artikel + 2 eager load penulis, 1 video.
 */
class LatestGuideController extends Controller
{
    private const PER_TYPE = 3;

    public function __invoke(Request $request): JsonResponse
    {
        $articles = GuideArticle::query()
            ->select(['id', 'guide_category_id', 'title', 'slug', 'image', 'author_member_id', 'author_user_id', 'published_at'])
            ->visible()
            ->with([
                'authorMember:id,nama_lengkap,no_lambung',
                'authorUser:id,name',
            ])
            ->latest('published_at')
            ->latest('id')
            ->limit(self::PER_TYPE)
            ->get();

        $videos = GuideVideo::query()
            ->select(['id', 'guide_category_id', 'title', 'thumbnail', 'youtube_id', 'created_at'])
            ->visible()
            ->latest('created_at')
            ->latest('id')
            ->limit(self::PER_TYPE)
            ->get();

        $articleCards = GuideArticleCardResource::collection($articles)->resolve($request);
        $videoCards = GuideVideoCardResource::collection($videos)->resolve($request);

        $items = [];
        for ($i = 0, $max = max(count($articleCards), count($videoCards)); $i < $max; $i++) {
            if (isset($articleCards[$i])) {
                $items[] = $articleCards[$i];
            }
            if (isset($videoCards[$i])) {
                $items[] = $videoCards[$i];
            }
        }

        return response()->json(['data' => $items]);
    }
}
