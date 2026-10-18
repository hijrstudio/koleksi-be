<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\VehicleBrandRequest;
use App\Models\VehicleBrand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

/**
 * Master merek kendaraan: dipakai dropdown Merek (form daftar / profil member) & grid logo EV Garage.
 */
class VehicleBrandController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', VehicleBrand::class);

        $active = $request->query('status');

        $brands = VehicleBrand::query()
            ->with('updater:id,name')
            ->withCount([
                'models',
                'models as garage_models_count' => fn ($q) => $q->garage(),
            ])
            ->search(mb_substr($request->string('search')->trim()->toString(), 0, 100))
            ->when(in_array($active, ['active', 'inactive'], true), fn ($q) => $q->where('is_active', $active === 'active'))
            ->ordered()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.vehicle-brands.index', [
            'title' => 'Merek Kendaraan',
            'brands' => $brands,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', VehicleBrand::class);

        return $this->form(new VehicleBrand(['is_active' => true, 'sort_order' => VehicleBrand::nextSort()]));
    }

    public function store(VehicleBrandRequest $request): RedirectResponse
    {
        $this->save($request, new VehicleBrand);

        return redirect()->route('vehicle-brands.index')->with('success', 'Merek berhasil ditambahkan.');
    }

    public function edit(VehicleBrand $vehicleBrand): View
    {
        Gate::authorize('update', $vehicleBrand);

        return $this->form($vehicleBrand);
    }

    public function update(VehicleBrandRequest $request, VehicleBrand $vehicleBrand): RedirectResponse
    {
        $this->save($request, $vehicleBrand);

        return redirect()->route('vehicle-brands.index')->with('success', 'Merek berhasil diperbarui.');
    }

    /**
     * Merek yang masih dipakai kendaraan member tidak boleh dihapus (data member kehilangan mereknya);
     * cukup dinonaktifkan supaya hilang dari dropdown & EV Garage.
     */
    public function destroy(VehicleBrand $vehicleBrand): RedirectResponse
    {
        Gate::authorize('delete', $vehicleBrand);

        if ($vehicleBrand->isUsedByMembers()) {
            return back()->with('error', "Merek {$vehicleBrand->name} masih dipakai kendaraan member. Nonaktifkan saja agar tidak tampil.");
        }

        $logo = $vehicleBrand->logo_path;
        $vehicleBrand->delete(); // tipe-tipenya ikut terhapus (cascade)

        if ($logo) {
            Storage::disk(VehicleBrand::DISK)->delete($logo);
        }

        return redirect()->route('vehicle-brands.index')->with('success', 'Merek berhasil dihapus.');
    }

    private function save(VehicleBrandRequest $request, VehicleBrand $brand): void
    {
        $oldLogo = $brand->logo_path;
        $removeLogo = $request->removeLogo();
        $newLogo = $request->hasFile('logo')
            ? $request->file('logo')->store(VehicleBrand::DIRECTORY.'/'.now()->format('Y/m'), VehicleBrand::DISK)
            : null;

        try {
            DB::transaction(function () use ($request, $brand, $newLogo, $removeLogo) {
                $brand->fill($request->brandData());

                if ($newLogo) {
                    $brand->logo_path = $newLogo;
                } elseif ($removeLogo) {
                    $brand->logo_path = null;
                }

                $brand->save();
            });
        } catch (Throwable $e) {
            if ($newLogo) {
                Storage::disk(VehicleBrand::DISK)->delete($newLogo);
            }

            throw $e;
        }

        // Logo lama baru dihapus setelah data baru tersimpan
        if ($oldLogo && ($newLogo || $removeLogo)) {
            Storage::disk(VehicleBrand::DISK)->delete($oldLogo);
        }
    }

    private function form(VehicleBrand $brand): View
    {
        return view('pages.cms.vehicle-brands.form', [
            'title' => $brand->exists ? 'Edit Merek' : 'Tambah Merek',
            'brand' => $brand->loadMissing(['creator:id,name', 'updater:id,name']),
        ]);
    }
}
