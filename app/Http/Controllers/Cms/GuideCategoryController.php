<?php

namespace App\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\GuideCategoryRequest;
use App\Models\GuideCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class GuideCategoryController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', GuideCategory::class);

        $status = ContentStatus::tryFrom((string) $request->query('status'));

        $categories = GuideCategory::query()
            ->with('updater:id,name')
            ->withCount(['articles', 'videos', 'infographics'])
            ->search($request->string('search')->trim()->toString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.guide-categories.index', [
            'title' => 'Kategori Panduan Praktis',
            'categories' => $categories,
            'statuses' => ContentStatus::cases(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', GuideCategory::class);

        return $this->form(new GuideCategory(['status' => ContentStatus::Draft]));
    }

    public function store(GuideCategoryRequest $request): RedirectResponse
    {
        GuideCategory::create($request->categoryData());

        return redirect()->route('guide-categories.index')->with('success', 'Kategori berhasil ditambahkan.');
    }

    public function edit(GuideCategory $guideCategory): View
    {
        Gate::authorize('update', $guideCategory);

        return $this->form($guideCategory);
    }

    public function update(GuideCategoryRequest $request, GuideCategory $guideCategory): RedirectResponse
    {
        $guideCategory->update($request->categoryData());

        return redirect()->route('guide-categories.index')->with('success', 'Kategori berhasil diperbarui.');
    }

    public function destroy(GuideCategory $guideCategory): RedirectResponse
    {
        Gate::authorize('delete', $guideCategory);

        // Dijaga juga oleh foreign key (restrictOnDelete) di database
        if ($guideCategory->isInUse()) {
            return back()->with('error', 'Kategori masih dipakai oleh artikel / video / infografis. Pindahkan atau hapus datanya terlebih dahulu.');
        }

        $guideCategory->delete();

        return redirect()->route('guide-categories.index')->with('success', 'Kategori berhasil dihapus.');
    }

    private function form(GuideCategory $category): View
    {
        return view('pages.cms.guide-categories.form', [
            'title' => $category->exists ? 'Edit Kategori' : 'Tambah Kategori',
            'category' => $category->loadMissing(['creator:id,name', 'updater:id,name']),
            'statuses' => ContentStatus::cases(),
        ]);
    }
}
