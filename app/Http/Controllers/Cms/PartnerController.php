<?php

namespace App\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\PartnerRequest;
use App\Models\Partner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class PartnerController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Partner::class);

        $status = ContentStatus::tryFrom((string) $request->query('status'));

        $partners = Partner::query()
            ->with('updater:id,name')
            ->search($request->string('search')->trim()->toString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->ordered()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.partners.index', [
            'title' => 'Partner',
            'partners' => $partners,
            'statuses' => ContentStatus::cases(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Partner::class);

        return $this->form(new Partner(['status' => ContentStatus::Draft, 'sort' => Partner::nextSort()]));
    }

    public function store(PartnerRequest $request): RedirectResponse
    {
        $this->save($request, new Partner);

        return redirect()->route('partners.index')->with('success', 'Partner berhasil ditambahkan.');
    }

    public function edit(Partner $partner): View
    {
        Gate::authorize('update', $partner);

        return $this->form($partner);
    }

    public function update(PartnerRequest $request, Partner $partner): RedirectResponse
    {
        $this->save($request, $partner);

        return redirect()->route('partners.index')->with('success', 'Partner berhasil diperbarui.');
    }

    public function destroy(Partner $partner): RedirectResponse
    {
        Gate::authorize('delete', $partner);

        $logo = $partner->logo;
        $partner->delete();
        Storage::disk(Partner::DISK)->delete($logo);

        return redirect()->route('partners.index')->with('success', 'Partner berhasil dihapus.');
    }

    private function save(PartnerRequest $request, Partner $partner): void
    {
        $oldLogo = $partner->logo;
        $newLogo = $request->hasFile('logo')
            ? $request->file('logo')->store(Partner::DIRECTORY.'/'.now()->format('Y/m'), Partner::DISK)
            : null;

        try {
            DB::transaction(function () use ($request, $partner, $newLogo) {
                $partner->fill($request->partnerData());

                if ($newLogo) {
                    $partner->logo = $newLogo;
                }

                $partner->save();
            });
        } catch (Throwable $e) {
            if ($newLogo) {
                Storage::disk(Partner::DISK)->delete($newLogo);
            }

            throw $e;
        }

        // Logo lama baru dihapus setelah data baru tersimpan
        if ($newLogo && $oldLogo) {
            Storage::disk(Partner::DISK)->delete($oldLogo);
        }
    }

    private function form(Partner $partner): View
    {
        return view('pages.cms.partners.form', [
            'title' => $partner->exists ? 'Edit Partner' : 'Tambah Partner',
            'partner' => $partner->loadMissing(['creator:id,name', 'updater:id,name']),
            'statuses' => ContentStatus::cases(),
        ]);
    }
}
