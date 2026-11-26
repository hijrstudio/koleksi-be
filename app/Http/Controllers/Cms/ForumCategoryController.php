<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Models\ForumCategory;
use App\Services\ForumService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Kategori diskusi forum. Kategori nonaktif tidak bisa dipilih saat membuat diskusi baru. */
class ForumCategoryController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', ForumCategory::class);

        return view('pages.cms.forum-categories.index', [
            'title' => 'Kategori Forum',
            'categories' => ForumCategory::query()->withCount('discussions')->ordered()->get(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', ForumCategory::class);

        return $this->form(new ForumCategory(['is_active' => true, 'sort_order' => ForumCategory::max('sort_order') + 1]));
    }

    public function store(Request $request, ForumService $forum): RedirectResponse
    {
        Gate::authorize('create', ForumCategory::class);

        ForumCategory::create($this->validated($request));
        $forum->forgetStats();

        return redirect()->route('forum-categories.index')->with('success', 'Kategori forum ditambahkan.');
    }

    public function edit(ForumCategory $forumCategory): View
    {
        Gate::authorize('update', $forumCategory);

        return $this->form($forumCategory);
    }

    public function update(Request $request, ForumCategory $forumCategory, ForumService $forum): RedirectResponse
    {
        Gate::authorize('update', $forumCategory);

        $forumCategory->update($this->validated($request, $forumCategory));
        $forum->forgetStats();

        return redirect()->route('forum-categories.index')->with('success', 'Kategori forum diperbarui.');
    }

    public function destroy(ForumCategory $forumCategory): RedirectResponse
    {
        Gate::authorize('delete', $forumCategory);

        // Dijaga juga oleh foreign key (restrictOnDelete); diskusi yang dihapus (soft delete) tetap terhitung
        if ($forumCategory->discussions()->withTrashed()->exists()) {
            return back()->with('error', 'Kategori masih dipakai diskusi. Nonaktifkan saja supaya tidak bisa dipilih lagi.');
        }

        $forumCategory->delete();

        return redirect()->route('forum-categories.index')->with('success', 'Kategori forum dihapus.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ForumCategory $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:60', Rule::unique('forum_categories', 'name')->ignore($category?->id)],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'is_active' => ['required', 'boolean'],
        ], [], ['name' => 'nama kategori', 'sort_order' => 'urutan']);

        // Slug tetap saat nama diubah (link filter kategori di website tidak berubah)
        $slug = $category?->slug ?? Str::slug($data['name']);
        if (! $category && ForumCategory::where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(4));
        }

        return [...$data, 'slug' => $slug ?: Str::lower(Str::random(8))];
    }

    private function form(ForumCategory $category): View
    {
        return view('pages.cms.forum-categories.form', [
            'title' => $category->exists ? 'Edit Kategori Forum' : 'Tambah Kategori Forum',
            'category' => $category,
        ]);
    }
}
