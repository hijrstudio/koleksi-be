<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberVehicle;
use App\Models\VehicleBrand;
use App\Support\VehicleCatalogInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kendaraan member yang merek/tipenya ditulis sendiri (belum ada di katalog), dikelompokkan per tulisan yang sama
 * (tanpa beda huruf besar/kecil). Admin menambahkan merek/tipe ke katalog (menu EV Garage), lalu menautkan satu
 * kelompok sekaligus: semua kendaraannya diisi merek/tipe katalog dan teks bebasnya dikosongkan.
 */
class MemberVehicleCatalogController extends Controller
{
    private const PER_PAGE = 25;

    public function index(): View
    {
        Gate::authorize('viewAny', Member::class);

        $groups = MemberVehicle::query()
            ->uncatalogued()
            ->whereHas('member')   // tanpa member yang sudah dihapus
            ->leftJoin('vehicle_brands', 'vehicle_brands.id', '=', 'member_vehicles.vehicle_brand_id')
            ->selectRaw('member_vehicles.vehicle_brand_id, vehicle_brands.name AS brand_name,
                MAX(member_vehicles.brand_other) AS brand_other, MAX(member_vehicles.model_other) AS model_other,
                COUNT(*) AS total')
            ->groupBy('member_vehicles.vehicle_brand_id', 'vehicle_brands.name',
                DB::raw('LOWER(member_vehicles.brand_other)'), DB::raw('LOWER(member_vehicles.model_other)'))
            ->orderByDesc('total')
            ->orderBy('brand_name')
            ->paginate(self::PER_PAGE);

        $brands = VehicleBrand::query()
            ->with(['models' => fn ($q) => $q->orderBy('name')->select('id', 'vehicle_brand_id', 'name', 'is_active')])
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return view('pages.cms.member-vehicles.uncatalogued', [
            'title' => 'Kendaraan di Luar Katalog',
            'groups' => $groups,
            'brands' => $brands->map(fn (VehicleBrand $b) => [
                'id' => $b->id,
                'name' => $b->name.($b->is_active ? '' : ' (nonaktif)'),
                'models' => $b->models->map(fn ($m) => ['id' => $m->id, 'name' => $m->name.($m->is_active ? '' : ' (nonaktif)')])->values(),
            ])->values(),
        ]);
    }

    /** Tautkan satu kelompok (tulisan merek/tipe yang sama) ke merek & tipe katalog. */
    public function link(Request $request): RedirectResponse
    {
        Gate::authorize('updateSettings', Member::class);

        $data = $request->validate([
            'source_brand_id' => ['nullable', 'integer'],
            'source_brand_other' => ['nullable', 'string', 'max:'.VehicleCatalogInput::BRAND_MAX],
            'source_model_other' => ['nullable', 'string', 'max:'.VehicleCatalogInput::MODEL_MAX],
            'vehicle_brand_id' => ['required', 'integer', Rule::exists('vehicle_brands', 'id')],
            'vehicle_model_id' => ['required', 'integer',
                Rule::exists('vehicle_models', 'id')->where('vehicle_brand_id', (int) $request->input('vehicle_brand_id'))],
        ], [
            'vehicle_brand_id.required' => 'Pilih merek katalog.',
            'vehicle_model_id.required' => 'Pilih tipe katalog.',
            'vehicle_model_id.exists' => 'Tipe tidak cocok dengan merek yang dipilih.',
        ]);

        $sourceBrandOther = VehicleCatalogInput::clean($data['source_brand_other'] ?? null);
        $sourceModelOther = VehicleCatalogInput::clean($data['source_model_other'] ?? null);
        $lower = fn (string $s) => mb_strtolower($s);

        // Kelompok yang sama persis dengan baris di halaman (merek & tipe, tanpa beda huruf besar/kecil)
        $linked = MemberVehicle::query()
            ->uncatalogued()
            ->whereHas('member')
            ->when(
                filled($data['source_brand_id'] ?? null),
                fn (Builder $q) => $q->where('vehicle_brand_id', (int) $data['source_brand_id']),
                fn (Builder $q) => $q->whereNull('vehicle_brand_id')->when(
                    $sourceBrandOther,
                    fn (Builder $q, string $v) => $q->whereRaw('LOWER(brand_other) = ?', [$lower($v)]),
                    fn (Builder $q) => $q->whereNull('brand_other'),
                ),
            )
            ->when(
                $sourceModelOther,
                fn (Builder $q, string $v) => $q->whereRaw('LOWER(model_other) = ?', [$lower($v)]),
                fn (Builder $q) => $q->whereNull('model_other'),
            )
            ->update([
                'vehicle_brand_id' => (int) $data['vehicle_brand_id'],
                'vehicle_model_id' => (int) $data['vehicle_model_id'],
                'brand_other' => null,
                'model_other' => null,
                'updated_at' => now(),
            ]);

        Log::info('Kendaraan di luar katalog ditautkan', [
            'by' => $request->user()->id,
            'from' => trim(($sourceBrandOther ?? '#'.($data['source_brand_id'] ?? '-')).' / '.($sourceModelOther ?? '-')),
            'to_model_id' => (int) $data['vehicle_model_id'],
            'vehicles' => $linked,
        ]);

        return redirect()->route('member-vehicles.uncatalogued')
            ->with($linked > 0 ? 'success' : 'error', $linked > 0
                ? "{$linked} kendaraan ditautkan ke katalog."
                : 'Tidak ada kendaraan yang ditautkan (mungkin sudah ditautkan admin lain).');
    }
}
