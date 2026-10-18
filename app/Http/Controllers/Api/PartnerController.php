<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PartnerResource;
use App\Models\Partner;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PartnerController extends Controller
{
    /**
     * Partner published sesuai urutan CMS (sort, lalu id).
     * Satu query tanpa relasi (tidak N+1), memakai index (status, sort).
     * Pembagian baris tampilan dilakukan di FE.
     */
    public function index(): AnonymousResourceCollection
    {
        $partners = Partner::query()
            ->select(['id', 'title', 'logo', 'url'])
            ->published()
            ->ordered()
            ->get();

        return PartnerResource::collection($partners);
    }
}
