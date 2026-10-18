<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleBrand;
use Illuminate\Http\JsonResponse;

/** Merek + tipe kendaraan aktif untuk dropdown Merek / Tipe. */
class VehicleBrandController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $brands = VehicleBrand::active()
            ->with(['models' => fn ($q) => $q->active()->orderBy('name')->select('id', 'vehicle_brand_id', 'name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'data' => $brands->map(fn (VehicleBrand $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'models' => $b->models->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            ]),
        ]);
    }
}
