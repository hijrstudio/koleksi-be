<?php

namespace App\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Http\Controllers\Cms\Concerns\ManagesAuthoredContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\GuideArticleRequest;
use App\Models\GuideArticle;
use App\Models\GuideCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class GuideArticleController extends Controller
{
    use ManagesAuthoredContent;

    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', GuideArticle::class);

        $status = ContentStatus::tryFrom((string) $request->query('status'));
        $categoryId = $request->integer('category') ?: null;

        $articles = GuideArticle::query()
            ->with(['category:id,name', 'authorMember:id,nama_lengkap', 'authorUser:id,name', 'updater:id,name'])
            ->search($request->string('search')->trim()->toString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($categoryId, fn ($q) => $q->where('guide_category_id', $categoryId))
            ->authoredBy($request->string('author')->toString() ?: null)
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.guide-articles.index', [
            'title' => 'Artikel Panduan Praktis',
            'articles' => $articles,
            'statuses' => ContentStatus::cases(),
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', GuideArticle::class);

        $article = new GuideArticle(['status' => ContentStatus::Draft]);
        $article->setAuthorUser($request->user()->id);

        return $this->form($article);
    }

    public function store(GuideArticleRequest $request): RedirectResponse
    {
        $this->saveAuthoredContent($request, new GuideArticle);

        return redirect()->route('guide-articles.index')->with('success', 'Artikel berhasil ditambahkan.');
    }

    public function edit(GuideArticle $guideArticle): View
    {
        Gate::authorize('update', $guideArticle);

        return $this->form($guideArticle);
    }

    public function update(GuideArticleRequest $request, GuideArticle $guideArticle): RedirectResponse
    {
        $this->saveAuthoredContent($request, $guideArticle);

        return redirect()->route('guide-articles.index')->with('success', 'Artikel berhasil diperbarui.');
    }

    public function destroy(GuideArticle $guideArticle): RedirectResponse
    {
        Gate::authorize('delete', $guideArticle);

        $this->deleteAuthoredContent($guideArticle);

        return redirect()->route('guide-articles.index')->with('success', 'Artikel berhasil dihapus.');
    }

    /**
     * GET /guide-articles/member-options?q=budi
     */
    public function memberOptions(Request $request): JsonResponse
    {
        $this->authorizeWriter($request);

        return $this->memberOptionsResponse($request);
    }

    /**
     * POST /guide-articles/content-images  (multipart: image)
     */
    public function uploadContentImage(Request $request): JsonResponse
    {
        $this->authorizeWriter($request);

        return $this->contentImageResponse($request, GuideArticle::CONTENT_IMAGE_DIRECTORY);
    }

    private function authorizeWriter(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->can('create', GuideArticle::class) || $user->can('guide-articles.update'), 403);
    }

    private function form(GuideArticle $article): View
    {
        $article->loadMissing(['authorMember:id,nama_lengkap,no_lambung', 'authorUser:id,name', 'creator:id,name', 'updater:id,name']);

        return view('pages.cms.guide-articles.form', [
            'title' => $article->exists ? 'Edit Artikel' : 'Tambah Artikel',
            'article' => $article,
            'statuses' => ContentStatus::cases(),
            'categories' => $this->categoryOptions(),
            'selectedMember' => $this->selectedMember($article),
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
