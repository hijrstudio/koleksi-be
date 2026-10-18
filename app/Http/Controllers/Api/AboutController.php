<?php

namespace App\Http\Controllers\Api;

use App\Enums\ManagementGroup;
use App\Http\Controllers\Controller;
use App\Http\Resources\AboutUsResource;
use App\Http\Resources\FaqResource;
use App\Http\Resources\ManagementMemberResource;
use App\Models\AboutUs;
use App\Models\Faq;
use App\Models\ManagementPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Semua data halaman "Tentang Kami" dalam satu response.
 * Jumlah query tetap 4 (about_us, periode, pengurus via eager load, faq), tidak N+1.
 */
class AboutController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $about = AboutUs::current();

        // Periode published terbaru saja yang tampil di website
        $period = ManagementPeriod::query()
            ->select(['id', 'start_year', 'end_year'])
            ->published()
            ->orderByDesc('start_year')
            ->orderByDesc('id')
            ->with('members:id,management_period_id,group,name,position,photo,sort_order')
            ->first();

        $faqs = Faq::query()
            ->select(['id', 'question', 'answer'])
            ->published()
            ->ordered()
            ->get();

        return response()->json([
            'data' => [
                ...(new AboutUsResource($about))->resolve($request),
                'management' => $period ? [
                    'period' => [
                        'start_year' => $period->start_year,
                        'end_year' => $period->end_year,
                        'label' => $period->label(),
                    ],
                    // members sudah urut (group, sort_order) dari relasi
                    'penasihat' => ManagementMemberResource::collection(
                        $period->members->where('group', ManagementGroup::Penasihat)->values()
                    )->resolve($request),
                    'bidang' => ManagementMemberResource::collection(
                        $period->members->where('group', ManagementGroup::Bidang)->values()
                    )->resolve($request),
                ] : null,
                'faqs' => FaqResource::collection($faqs)->resolve($request),
            ],
        ]);
    }
}
