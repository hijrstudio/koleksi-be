<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SponsorResource;
use App\Models\Sponsor;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SponsorController extends Controller
{
    /**
     * Sponsor published sesuai urutan CMS (sort, lalu id).
     * Satu query tanpa relasi (tidak N+1), memakai index (status, sort).
     * Pembagian baris tampilan dilakukan di FE.
     */
    public function index(): AnonymousResourceCollection
    {
        $sponsors = Sponsor::query()
            ->select(['id', 'title', 'logo', 'url'])
            ->published()
            ->ordered()
            ->get();

        return SponsorResource::collection($sponsors);
    }
}
