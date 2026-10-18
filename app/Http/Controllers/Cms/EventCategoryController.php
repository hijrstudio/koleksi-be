<?php

namespace App\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\EventCategoryRequest;
use App\Models\EventCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EventCategoryController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', EventCategory::class);

        $status = ContentStatus::tryFrom((string) $request->query('status'));

        $categories = EventCategory::query()
            ->with('updater:id,name')
            ->withCount('events')
            ->search($request->string('search')->trim()->toString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.event-categories.index', [
            'title' => 'Kategori Kegiatan',
            'categories' => $categories,
            'statuses' => ContentStatus::cases(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', EventCategory::class);

        return $this->form(new EventCategory(['status' => ContentStatus::Draft]));
    }

    public function store(EventCategoryRequest $request): RedirectResponse
    {
        EventCategory::create($request->categoryData());

        return redirect()->route('event-categories.index')->with('success', 'Kategori berhasil ditambahkan.');
    }

    public function edit(EventCategory $eventCategory): View
    {
        Gate::authorize('update', $eventCategory);

        return $this->form($eventCategory);
    }

    public function update(EventCategoryRequest $request, EventCategory $eventCategory): RedirectResponse
    {
        $eventCategory->update($request->categoryData());

        return redirect()->route('event-categories.index')->with('success', 'Kategori berhasil diperbarui.');
    }

    public function destroy(EventCategory $eventCategory): RedirectResponse
    {
        Gate::authorize('delete', $eventCategory);

        // Dijaga juga oleh foreign key (restrictOnDelete) di database
        if ($eventCategory->events()->exists()) {
            return back()->with('error', 'Kategori masih dipakai oleh event. Pindahkan atau hapus event-nya terlebih dahulu.');
        }

        $eventCategory->delete();

        return redirect()->route('event-categories.index')->with('success', 'Kategori berhasil dihapus.');
    }

    private function form(EventCategory $category): View
    {
        return view('pages.cms.event-categories.form', [
            'title' => $category->exists ? 'Edit Kategori Kegiatan' : 'Tambah Kategori Kegiatan',
            'category' => $category->loadMissing(['creator:id,name', 'updater:id,name']),
            'statuses' => ContentStatus::cases(),
        ]);
    }
}
