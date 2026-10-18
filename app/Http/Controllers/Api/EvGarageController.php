<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EvGarageIndexRequest;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Data halaman EV Garage: merek & tipe aktif yang spesifikasinya lengkap (lihat VehicleModel::scopeGarage).
 */
class EvGarageController extends Controller
{
    /**
     * Grid logo merek (urutan CMS). Satu query dengan subquery EXISTS, tanpa N+1.
     */
    public function brands(): JsonResponse
    {
        $brands = VehicleBrand::query()
            ->select(['id', 'name', 'slug', 'logo_path'])
            ->inGarage()
            ->ordered()
            ->get()
            ->map(fn (VehicleBrand $brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logo' => $brand->logo_url,
            ]);

        return response()->json(['data' => $brands]);
    }

    /**
     * Tabel spesifikasi: filter merek (slug), pencarian merek/tipe, urut merek lalu tipe A-Z / Z-A.
     * JOIN ke merek (bukan eager load) supaya bisa diurutkan per nama merek: 2 query (COUNT + data).
     */
    public function vehicles(EvGarageIndexRequest $request): JsonResponse
    {
        $direction = $request->sortDirection();
        $search = $request->search();

        $vehicles = VehicleModel::query()
            ->select([
                'vehicle_models.id', 'vehicle_models.name', 'vehicle_models.battery_kwh', 'vehicle_models.ac_kw',
                'vehicle_models.dc_kw', 'vehicle_models.range_km',
                'vehicle_brands.id as brand_id', 'vehicle_brands.name as brand_name', 'vehicle_brands.slug as brand_slug',
            ])
            ->join('vehicle_brands', 'vehicle_brands.id', '=', 'vehicle_models.vehicle_brand_id')
            ->where('vehicle_brands.is_active', true)
            ->where('vehicle_models.is_active', true)
            ->whereNotNull('vehicle_models.battery_kwh')
            ->whereNotNull('vehicle_models.ac_kw')
            ->whereNotNull('vehicle_models.range_km')
            ->when($request->brand(), fn (Builder $q, string $slug) => $q->where('vehicle_brands.slug', $slug))
            ->when($search !== '', function (Builder $q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('vehicle_models.name', 'like', $like)->orWhere('vehicle_brands.name', 'like', $like));
            })
            ->orderBy('vehicle_brands.name', $direction)
            ->orderBy('vehicle_models.name', $direction)
            ->orderBy('vehicle_models.id', $direction)
            ->paginate($request->perPage());

        return response()->json([
            'data' => $vehicles->getCollection()->map(fn (VehicleModel $v) => [
                'id' => $v->id,
                'brand' => ['id' => $v->brand_id, 'name' => $v->brand_name, 'slug' => $v->brand_slug],
                'type' => $v->name,
                'battery_kwh' => (float) $v->battery_kwh,
                'ac_kw' => (float) $v->ac_kw,
                'dc_kw' => $v->dc_kw === null ? null : (float) $v->dc_kw, // null = tidak mendukung DC
                'range_km' => $v->range_km,
            ])->values(),
            'meta' => [
                'current_page' => $vehicles->currentPage(),
                'last_page' => $vehicles->lastPage(),
                'per_page' => $vehicles->perPage(),
                'total' => $vehicles->total(),
            ],
        ]);
    }
}
