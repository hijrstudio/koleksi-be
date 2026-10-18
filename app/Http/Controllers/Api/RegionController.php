<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\Regency;
use App\Models\Village;
use Illuminate\Http\JsonResponse;

/**
 * Master wilayah untuk dropdown bertingkat: kab/kota → kecamatan → desa/kelurahan.
 * Data jarang berubah (per Kepmendagri), jadi FE meng-cache-nya lama.
 */
class RegionController extends Controller
{
    /** Semua kab/kota (514), urut per provinsi lalu nama. */
    public function regencies(): JsonResponse
    {
        $data = Regency::query()
            ->join('provinces', 'provinces.code', '=', 'regencies.province_code')
            ->orderBy('regencies.province_code')
            ->orderBy('regencies.name')
            ->get(['regencies.code', 'regencies.name', 'provinces.name as province']);

        return response()->json(['data' => $data]);
    }

    public function districts(string $regency): JsonResponse
    {
        return response()->json([
            'data' => District::where('regency_code', $regency)->orderBy('name')->get(['code', 'name']),
        ]);
    }

    public function villages(string $district): JsonResponse
    {
        return response()->json([
            'data' => Village::where('district_code', $district)->orderBy('name')->get(['code', 'name']),
        ]);
    }
}
