<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HeroBannerResource;
use App\Models\HeroBanner;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HeroBannerController extends Controller
{
    /**
     * Hero banner yang sudah published, terbaru dulu.
     * Satu query, tanpa relasi (tidak ada N+1), hanya kolom yang dibutuhkan frontend.
     */
    public function index(): AnonymousResourceCollection
    {
        $banners = HeroBanner::query()
            ->select(['id', 'file_path', 'file_type', 'mime_type', 'title', 'updated_at'])
            ->published()
            ->latest('id')
            ->get();

        return HeroBannerResource::collection($banners);
    }
}
