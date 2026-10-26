<?php

namespace App\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\SponsorRequest;
use App\Models\Sponsor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class SponsorController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Sponsor::class);

        $status = ContentStatus::tryFrom((string) $request->query('status'));

        $sponsors = Sponsor::query()
            ->with('updater:id,name')
            ->search($request->string('search')->trim()->toString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->ordered()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.sponsors.index', [
            'title' => 'Sponsor',
            'sponsors' => $sponsors,
            'statuses' => ContentStatus::cases(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Sponsor::class);

        return $this->form(new Sponsor(['status' => ContentStatus::Draft, 'sort' => Sponsor::nextSort()]));
    }

    public function store(SponsorRequest $request): RedirectResponse
    {
        $this->save($request, new Sponsor);

        return redirect()->route('sponsors.index')->with('success', 'Sponsor berhasil ditambahkan.');
    }

    public function edit(Sponsor $sponsor): View
    {
        Gate::authorize('update', $sponsor);

        return $this->form($sponsor);
    }

    public function update(SponsorRequest $request, Sponsor $sponsor): RedirectResponse
    {
        $this->save($request, $sponsor);

        return redirect()->route('sponsors.index')->with('success', 'Sponsor berhasil diperbarui.');
    }

    public function destroy(Sponsor $sponsor): RedirectResponse
    {
        Gate::authorize('delete', $sponsor);

        $logo = $sponsor->logo;
        $sponsor->delete();
        Storage::disk(Sponsor::DISK)->delete($logo);

        return redirect()->route('sponsors.index')->with('success', 'Sponsor berhasil dihapus.');
    }

    private function save(SponsorRequest $request, Sponsor $sponsor): void
    {
        $oldLogo = $sponsor->logo;
        $newLogo = $request->hasFile('logo')
            ? $request->file('logo')->store(Sponsor::DIRECTORY.'/'.now()->format('Y/m'), Sponsor::DISK)
            : null;

        try {
            DB::transaction(function () use ($request, $sponsor, $newLogo) {
                $sponsor->fill($request->sponsorData());

                if ($newLogo) {
                    $sponsor->logo = $newLogo;
                }

                $sponsor->save();
            });
        } catch (Throwable $e) {
            if ($newLogo) {
                Storage::disk(Sponsor::DISK)->delete($newLogo);
            }

            throw $e;
        }

        // Logo lama baru dihapus setelah data baru tersimpan
        if ($newLogo && $oldLogo) {
            Storage::disk(Sponsor::DISK)->delete($oldLogo);
        }
    }

    private function form(Sponsor $sponsor): View
    {
        return view('pages.cms.sponsors.form', [
            'title' => $sponsor->exists ? 'Edit Sponsor' : 'Tambah Sponsor',
            'sponsor' => $sponsor->loadMissing(['creator:id,name', 'updater:id,name']),
            'statuses' => ContentStatus::cases(),
        ]);
    }
}
