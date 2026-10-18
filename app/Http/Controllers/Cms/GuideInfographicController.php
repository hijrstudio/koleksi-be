<?php

namespace App\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\GuideInfographicRequest;
use App\Models\GuideCategory;
use App\Models\GuideInfographic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class GuideInfographicController extends Controller
{
    private const PER_PAGE = 12;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', GuideInfographic::class);

        $status = ContentStatus::tryFrom((string) $request->query('status'));
        $categoryId = $request->integer('category') ?: null;

        $infographics = GuideInfographic::query()
            ->with(['category:id,name', 'updater:id,name'])
            ->search($request->string('search')->trim()->toString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($categoryId, fn ($q) => $q->where('guide_category_id', $categoryId))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.guide-infographics.index', [
            'title' => 'Infografis',
            'infographics' => $infographics,
            'statuses' => ContentStatus::cases(),
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', GuideInfographic::class);

        return $this->form(new GuideInfographic(['status' => ContentStatus::Draft]));
    }

    public function store(GuideInfographicRequest $request): RedirectResponse
    {
        $this->save($request, new GuideInfographic);

        return redirect()->route('guide-infographics.index')->with('success', 'Infografis berhasil ditambahkan.');
    }

    public function edit(GuideInfographic $guideInfographic): View
    {
        Gate::authorize('update', $guideInfographic);

        return $this->form($guideInfographic);
    }

    public function update(GuideInfographicRequest $request, GuideInfographic $guideInfographic): RedirectResponse
    {
        $this->save($request, $guideInfographic);

        return redirect()->route('guide-infographics.index')->with('success', 'Infografis berhasil diperbarui.');
    }

    public function destroy(GuideInfographic $guideInfographic): RedirectResponse
    {
        Gate::authorize('delete', $guideInfographic);

        $image = $guideInfographic->image;
        $guideInfographic->delete();
        Storage::disk(GuideInfographic::DISK)->delete($image);

        return redirect()->route('guide-infographics.index')->with('success', 'Infografis berhasil dihapus.');
    }

    private function save(GuideInfographicRequest $request, GuideInfographic $infographic): void
    {
        $oldImage = $infographic->image;
        $newImage = $request->hasFile('image')
            ? $request->file('image')->store(GuideInfographic::DIRECTORY.'/'.now()->format('Y/m'), GuideInfographic::DISK)
            : null;

        try {
            DB::transaction(function () use ($request, $infographic, $newImage) {
                $infographic->fill($request->infographicData());

                if ($newImage) {
                    $infographic->image = $newImage;
                }

                $infographic->save();
            });
        } catch (Throwable $e) {
            if ($newImage) {
                Storage::disk(GuideInfographic::DISK)->delete($newImage);
            }

            throw $e;
        }

        // Gambar lama baru dihapus setelah data baru tersimpan
        if ($newImage && $oldImage) {
            Storage::disk(GuideInfographic::DISK)->delete($oldImage);
        }
    }

    private function form(GuideInfographic $infographic): View
    {
        return view('pages.cms.guide-infographics.form', [
            'title' => $infographic->exists ? 'Edit Infografis' : 'Tambah Infografis',
            'infographic' => $infographic->loadMissing(['creator:id,name', 'updater:id,name']),
            'statuses' => ContentStatus::cases(),
            'categories' => $this->categoryOptions(),
        ]);
    }

    /**
     * @return Collection<int, GuideCategory>
     */
    private function categoryOptions(): Collection
    {
        return GuideCategory::query()->latest('id')->get(['id', 'name', 'status']);
    }
}
