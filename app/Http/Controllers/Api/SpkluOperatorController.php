<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SpkluOperatorIndexRequest;
use App\Http\Resources\SpkluOperatorResource;
use App\Models\SpkluOperator;
use Illuminate\Http\JsonResponse;

/**
 * Operator / brand SPKLU untuk website, hanya yang published.
 * Tanpa relasi (tidak N+1); memakai index (status, brand_spklu).
 */
class SpkluOperatorController extends Controller
{
    private const COLUMNS = [
        'id', 'brand_spklu', 'nama_operator', 'logo', 'aplikasi', 'link_playstore', 'link_appstore',
    ];

    /**
     * List accordion: pencarian (brand, operator, aplikasi), urut brand A-Z / Z-A,
     * paginasi bernomor 10 per halaman = 2 query (COUNT + data).
     */
    public function index(SpkluOperatorIndexRequest $request): JsonResponse
    {
        $operators = SpkluOperator::query()
            ->select(self::COLUMNS)
            ->published()
            ->search($request->search())
            ->ordered($request->sortDirection())
            ->paginate($request->perPage());

        return response()->json([
            'data' => SpkluOperatorResource::collection($operators->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $operators->currentPage(),
                'last_page' => $operators->lastPage(),
                'per_page' => $operators->perPage(),
                'total' => $operators->total(),
            ],
        ]);
    }

    /**
     * Semua brand published (A-Z) untuk slider logo: hanya kolom ringan, 1 query.
     * Urutannya sama dengan index(sort=asc), jadi FE bisa menghitung halaman tiap brand.
     */
    public function brands(): JsonResponse
    {
        $brands = SpkluOperator::query()
            ->select(['id', 'brand_spklu', 'logo'])
            ->published()
            ->ordered()
            ->get()
            ->map(fn (SpkluOperator $operator) => [
                'id' => $operator->id,
                'brand_spklu' => $operator->brand_spklu,
                'logo' => $operator->logo_url,
            ]);

        return response()->json(['data' => $brands]);
    }
}
