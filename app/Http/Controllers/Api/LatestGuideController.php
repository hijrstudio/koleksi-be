<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GuideArticleCardResource;
use App\Http\Resources\GuideVideoCardResource;
use App\Models\GuideArticle;
use App\Models\GuidePlaybook;
use App\Models\GuideVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panduan praktis untuk homepage: maks. 3 kartu dengan urutan tetap — Web Playbook terbaru, Video Edukasi terbaru,
 * Artikel terbaru (urutan "terbaru" sama dengan halaman list masing-masing). Jenis yang belum punya konten
 * published dilewati.
 *
 * Bentuk tiap item sama: id, type (playbook | video | article), title, author, image, slug, youtube_id,
 * published_at. Playbook dibuka website di popup PDF (lewat id), video di popup YouTube, artikel ke halaman detail.
 *
 * Jumlah query tetap (tidak N+1): 1 playbook, 1 video, 1 artikel + 2 eager load penulis.
 */
class LatestGuideController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $playbook = GuidePlaybook::query()
            ->select(['id', 'title', 'cover_image', 'created_at'])
            ->published()
            ->latest('created_at')
            ->latest('id')
            ->first();

        $video = GuideVideo::query()
            ->select(['id', 'guide_category_id', 'title', 'thumbnail', 'youtube_id', 'created_at'])
            ->visible()
            ->latest('created_at')
            ->latest('id')
            ->first();

        $article = GuideArticle::query()
            ->select(['id', 'guide_category_id', 'title', 'slug', 'image', 'author_member_id', 'author_user_id', 'published_at'])
            ->visible()
            ->with([
                'authorMember:id,nama_lengkap,no_lambung',
                'authorUser:id,name',
            ])
            ->latest('published_at')
            ->latest('id')
            ->first();

        $items = array_values(array_filter([
            $playbook ? [
                'id' => $playbook->id,
                'type' => 'playbook',
                'title' => [
                    'id' => $playbook->title['id'] ?? '',
                    'en' => ($playbook->title['en'] ?? null) ?: ($playbook->title['id'] ?? ''),
                ],
                'author' => null,
                'image' => $playbook->cover_url,
                'slug' => null,
                'youtube_id' => null,
                'published_at' => $playbook->created_at?->toIso8601String(),
            ] : null,
            $video ? (new GuideVideoCardResource($video))->resolve($request) : null,
            $article ? (new GuideArticleCardResource($article))->resolve($request) : null,
        ]));

        return response()->json(['data' => $items]);
    }
}
