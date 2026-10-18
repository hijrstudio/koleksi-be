<?php

namespace App\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Enums\NewsCategory;
use App\Http\Controllers\Cms\Concerns\ManagesAuthoredContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\NewsRequest;
use App\Models\News;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class NewsController extends Controller
{
    use ManagesAuthoredContent;

    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', News::class);

        $status = ContentStatus::tryFrom((string) $request->query('status'));
        $category = NewsCategory::tryFrom((string) $request->query('category'));

        $news = News::query()
            ->with(['authorMember:id,nama_lengkap', 'authorUser:id,name', 'updater:id,name'])
            ->search($request->string('search')->trim()->toString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($category, fn ($q) => $q->where('category', $category))
            ->authoredBy($request->string('author')->toString() ?: null)
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.news.index', [
            'title' => 'Berita',
            'news' => $news,
            'statuses' => ContentStatus::cases(),
            'categories' => NewsCategory::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', News::class);

        $news = new News(['status' => ContentStatus::Draft]);
        $news->setAuthorUser($request->user()->id);

        return $this->form($news);
    }

    public function store(NewsRequest $request): RedirectResponse
    {
        $this->saveAuthoredContent($request, new News);

        return redirect()->route('news.index')->with('success', 'Berita berhasil ditambahkan.');
    }

    public function edit(News $news): View
    {
        Gate::authorize('update', $news);

        return $this->form($news);
    }

    public function update(NewsRequest $request, News $news): RedirectResponse
    {
        $this->saveAuthoredContent($request, $news);

        return redirect()->route('news.index')->with('success', 'Berita berhasil diperbarui.');
    }

    public function destroy(News $news): RedirectResponse
    {
        Gate::authorize('delete', $news);

        $this->deleteAuthoredContent($news);

        return redirect()->route('news.index')->with('success', 'Berita berhasil dihapus.');
    }

    /**
     * GET /news/member-options?q=budi
     */
    public function memberOptions(Request $request): JsonResponse
    {
        $this->authorizeWriter($request);

        return $this->memberOptionsResponse($request);
    }

    /**
     * POST /news/content-images  (multipart: image)
     */
    public function uploadContentImage(Request $request): JsonResponse
    {
        $this->authorizeWriter($request);

        return $this->contentImageResponse($request, News::CONTENT_IMAGE_DIRECTORY);
    }

    private function authorizeWriter(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->can('create', News::class) || $user->can('news.update'), 403);
    }

    private function form(News $news): View
    {
        $news->loadMissing(['authorMember:id,nama_lengkap,no_lambung', 'authorUser:id,name', 'creator:id,name', 'updater:id,name']);

        return view('pages.cms.news.form', [
            'title' => $news->exists ? 'Edit Berita' : 'Tambah Berita',
            'news' => $news,
            'statuses' => ContentStatus::cases(),
            'categories' => NewsCategory::cases(),
            'selectedMember' => $this->selectedMember($news),
        ]);
    }
}
