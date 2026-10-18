<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\AboutUsRequest;
use App\Models\AboutUs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

/**
 * "Tentang Kami" hanya punya 1 row, jadi tidak ada halaman list:
 * menu langsung membuka form, dan simpan = create (pertama kali) atau update.
 */
class AboutUsController extends Controller
{
    public function edit(): View
    {
        Gate::authorize('view', AboutUs::class);

        $aboutUs = AboutUs::current()->loadMissing(['creator:id,name', 'updater:id,name']);

        return view('pages.cms.about-us.form', [
            'title' => 'Tentang Kami',
            'aboutUs' => $aboutUs,
            'canUpdate' => request()->user()->can('update', AboutUs::class),
        ]);
    }

    public function update(AboutUsRequest $request): RedirectResponse
    {
        // Upload dilakukan di luar transaksi agar lock DB tidak tertahan selama I/O file
        $newFiles = [];

        try {
            foreach (AboutUs::FILE_FIELDS as $field) {
                if ($request->hasFile($field)) {
                    $newFiles[$field] = $request->file($field)->store(
                        AboutUs::DIRECTORY.'/'.$field.'/'.now()->format('Y/m'),
                        AboutUs::DISK
                    );
                }
            }

            $obsoleteFiles = DB::transaction(function () use ($request, $newFiles) {
                $aboutUs = AboutUs::query()->lockForUpdate()->find(AboutUs::SINGLETON_ID)
                    ?? tap(new AboutUs, fn (AboutUs $model) => $model->id = AboutUs::SINGLETON_ID);

                $obsolete = [];
                $data = $request->textData();

                foreach (AboutUs::FILE_FIELDS as $field) {
                    if (isset($newFiles[$field])) {
                        $data[$field] = $newFiles[$field];
                    } elseif ($request->boolean("remove_$field")) {
                        $data[$field] = null;
                    } else {
                        continue;
                    }

                    if ($aboutUs->{$field}) {
                        $obsolete[] = $aboutUs->{$field};
                    }
                }

                $aboutUs->fill($data)->save();

                return $obsolete;
            });
        } catch (Throwable $e) {
            Storage::disk(AboutUs::DISK)->delete(array_values($newFiles));

            throw $e;
        }

        // File lama baru dihapus setelah data baru tersimpan
        if ($obsoleteFiles) {
            Storage::disk(AboutUs::DISK)->delete($obsoleteFiles);
        }

        return redirect()->route('about-us.edit')->with('success', 'Tentang Kami berhasil disimpan.');
    }
}
