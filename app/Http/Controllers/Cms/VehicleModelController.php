<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\VehicleModelRequest;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Master tipe kendaraan + spesifikasi EV Garage (baterai, AC, DC, jangkauan).
 * Tipe aktif dengan spesifikasi lengkap tampil di halaman /ev-garage.
 */
class VehicleModelController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', VehicleModel::class);

        $search = mb_substr($request->string('search')->trim()->toString(), 0, 100);
        $brandId = $request->integer('brand') ?: null;
        $status = $request->query('status');
        $specs = $request->query('specs');

        $models = VehicleModel::query()
            ->select('vehicle_models.*')
            ->join('vehicle_brands', 'vehicle_brands.id', '=', 'vehicle_models.vehicle_brand_id')
            ->with(['brand:id,name,is_active', 'updater:id,name'])
            ->when($search !== '', function (Builder $q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('vehicle_models.name', 'like', $like)->orWhere('vehicle_brands.name', 'like', $like));
            })
            ->when($brandId, fn (Builder $q) => $q->where('vehicle_models.vehicle_brand_id', $brandId))
            ->when(in_array($status, ['active', 'inactive'], true), fn (Builder $q) => $q->where('vehicle_models.is_active', $status === 'active'))
            ->when($specs === 'complete', fn (Builder $q) => $q->whereNotNull('battery_kwh')->whereNotNull('ac_kw')->whereNotNull('range_km'))
            ->when($specs === 'incomplete', fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('battery_kwh')->orWhereNull('ac_kw')->orWhereNull('range_km')))
            ->orderBy('vehicle_brands.name')
            ->orderBy('vehicle_models.name')
            ->orderBy('vehicle_models.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.vehicle-models.index', [
            'title' => 'Tipe & Spesifikasi',
            'models' => $models,
            'brands' => $this->brandOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', VehicleModel::class);

        return $this->form(new VehicleModel([
            'is_active' => true,
            'vehicle_brand_id' => $request->integer('brand') ?: null, // dari tombol "Tambah tipe" di filter merek
        ]));
    }

    public function store(VehicleModelRequest $request): RedirectResponse
    {
        VehicleModel::create($request->modelData());

        return redirect()->route('vehicle-models.index')->with('success', 'Tipe kendaraan berhasil ditambahkan.');
    }

    public function edit(VehicleModel $vehicleModel): View
    {
        Gate::authorize('update', $vehicleModel);

        return $this->form($vehicleModel);
    }

    public function update(VehicleModelRequest $request, VehicleModel $vehicleModel): RedirectResponse
    {
        $vehicleModel->update($request->modelData());

        return redirect()->route('vehicle-models.index')->with('success', 'Tipe kendaraan berhasil diperbarui.');
    }

    /**
     * Tipe yang masih dipakai kendaraan member tidak boleh dihapus; cukup dinonaktifkan.
     */
    public function destroy(VehicleModel $vehicleModel): RedirectResponse
    {
        Gate::authorize('delete', $vehicleModel);

        if ($vehicleModel->memberVehicles()->exists()) {
            return back()->with('error', "Tipe {$vehicleModel->name} masih dipakai kendaraan member. Nonaktifkan saja agar tidak tampil.");
        }

        $vehicleModel->delete();

        return redirect()->route('vehicle-models.index')->with('success', 'Tipe kendaraan berhasil dihapus.');
    }

    private function form(VehicleModel $model): View
    {
        return view('pages.cms.vehicle-models.form', [
            'title' => $model->exists ? 'Edit Tipe Kendaraan' : 'Tambah Tipe Kendaraan',
            'model' => $model->loadMissing(['creator:id,name', 'updater:id,name']),
            'brands' => $this->brandOptions(),
            'brandLocked' => $model->exists && $model->memberVehicles()->exists(),
        ]);
    }

    private function brandOptions()
    {
        return VehicleBrand::query()->ordered()->get(['id', 'name', 'is_active']);
    }
}
