<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Models\ForumDiscussion;
use App\Models\GuideArticle;
use App\Models\Member;
use App\Models\MemberBookmark;
use App\Models\News;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tombol Simpan (bookmark) member: diskusi forum, berita, artikel panduan. Konten dirujuk lewat tipe + slug.
 *
 *   GET    /bookmarks?lang=id&page=1      daftar simpanan (terbaru dulu), judul sesuai bahasa
 *   GET    /bookmarks/status?type=&slug=  sudah disimpan?
 *   PUT    /bookmarks  {type, slug}       simpan (idempoten)
 *   DELETE /bookmarks  {type, slug}       hapus dari simpanan
 */
class BookmarkController extends Controller
{
    private const PER_PAGE = 12;

    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'lang' => ['nullable', Rule::in(['id', 'en'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $lang = $params['lang'] ?? 'id';

        $page = $this->member($request)->bookmarks()->latest('id')->paginate(self::PER_PAGE);

        // Konten per tipe dalam 1 query per tipe; yang sudah tidak tampil di website dilewati
        $contents = collect(MemberBookmark::TYPES)->mapWithKeys(fn (string $type) => [
            $type => MemberBookmark::contentQuery($type)
                ->whereIn('id', $page->getCollection()->where('bookmarkable_type', $type)->pluck('bookmarkable_id'))
                ->get()->keyBy('id'),
        ]);

        $items = $page->getCollection()
            ->map(function (MemberBookmark $b) use ($contents, $lang) {
                $content = $contents[$b->bookmarkable_type]->get($b->bookmarkable_id);

                return $content ? [
                    'type' => $b->bookmarkable_type,
                    'slug' => $content->slug,
                    'title' => $this->title($content, $lang),
                    'image' => $content instanceof ForumDiscussion ? $content->imageUrl() : $content->image_url,
                    'saved_at' => $b->created_at?->toIso8601String(),
                ] : null;
            })
            ->filter()->values();

        return response()->json([
            'data' => $items,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $content = $this->content($request);

        return response()->json(['data' => ['saved' => $this->query($request, $content)->exists()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $content = $this->content($request);
        $member = $this->member($request);

        if (! $this->query($request, $content)->exists() && $member->bookmarks()->count() >= MemberBookmark::MAX_PER_MEMBER) {
            return response()->json(['message' => 'Simpanan sudah penuh ('.MemberBookmark::MAX_PER_MEMBER.'). Hapus beberapa dulu.'], 422);
        }

        // insertOrIgnore: klik ganda / request bersamaan tidak membuat baris ganda (UNIQUE di database)
        MemberBookmark::query()->insertOrIgnore([
            'member_id' => $member->id,
            'bookmarkable_type' => $request->input('type'),
            'bookmarkable_id' => $content->getKey(),
            'created_at' => now(),
        ]);

        return response()->json(['data' => ['saved' => true]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->query($request, $this->content($request))->delete();

        return response()->json(['data' => ['saved' => false]]);
    }

    /** Konten yang dirujuk (harus tampil di website), atau 404. */
    private function content(Request $request): Model
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(MemberBookmark::TYPES)],
            'slug' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9-]+$/'],
        ]);

        return MemberBookmark::contentQuery($data['type'])->where('slug', $data['slug'])->firstOrFail();
    }

    private function query(Request $request, Model $content)
    {
        return $this->member($request)->bookmarks()
            ->where('bookmarkable_type', $request->input('type'))
            ->where('bookmarkable_id', $content->getKey());
    }

    private function title(Model $content, string $lang): string
    {
        if ($content instanceof News || $content instanceof GuideArticle) {
            $title = (array) $content->title;

            return (string) (($title[$lang] ?? null) ?: ($title['id'] ?? ''));
        }

        return (string) $content->title;
    }

    private function member(Request $request): Member
    {
        /** @var Member */
        return $request->user();
    }
}
