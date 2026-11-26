<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\GuideArticle;
use App\Models\GuideVideo;
use App\Models\News;
use App\Support\JsonSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Pencarian website (publik): berita, artikel panduan, kegiatan, video edukasi — hanya yang tampil di website.
 *
 *   GET /search?q=&lang=id|en                 semua jenis: maks 4 per jenis + jumlah total per jenis
 *   GET /search?q=&type=news&page=2&lang=id   satu jenis, per halaman
 *
 * Cari di judul (ID & EN, tidak peka huruf besar-kecil). Hasil di-cache singkat per kata kunci (kata kunci
 * populer tidak menghantam database berulang) + rate limit per IP. Data yang dikirim hanya untuk kartu hasil.
 */
class SearchController extends Controller
{
    public const TYPES = ['news', 'article', 'event', 'video'];

    private const PREVIEW = 4;

    private const PER_PAGE = 12;

    public function __invoke(Request $request): JsonResponse
    {
        $params = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'type' => ['nullable', Rule::in(self::TYPES)],
            'lang' => ['nullable', Rule::in(['id', 'en'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $term = preg_replace('/\s+/u', ' ', trim($params['q']));
        $lang = $params['lang'] ?? 'id';
        $type = $params['type'] ?? null;
        $page = (int) ($params['page'] ?? 1);
        $key = 'search:'.md5(json_encode([mb_strtolower($term), $lang, $type, $page]));

        $data = Cache::remember($key, now()->addMinutes(2), fn () => $type
            ? $this->single($type, $term, $lang, $page)
            : $this->overview($term, $lang));

        return response()->json($data);
    }

    /** @return array<string, mixed> */
    private function overview(string $term, string $lang): array
    {
        $groups = [];
        foreach (self::TYPES as $type) {
            $query = $this->query($type, $term);
            $groups[] = [
                'type' => $type,
                'total' => (clone $query)->count(),
                'items' => $this->ordered($type, $query)->limit(self::PREVIEW)->get()
                    ->map(fn (Model $m) => $this->item($type, $m, $lang))->values(),
            ];
        }

        return ['data' => $groups, 'total' => array_sum(array_column($groups, 'total'))];
    }

    /** @return array<string, mixed> */
    private function single(string $type, string $term, string $lang, int $page): array
    {
        $result = $this->ordered($type, $this->query($type, $term))->paginate(self::PER_PAGE, ['*'], 'page', $page);

        return [
            'data' => $result->getCollection()->map(fn (Model $m) => $this->item($type, $m, $lang))->values(),
            'meta' => ['current_page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'total' => $result->total()],
        ];
    }

    private function query(string $type, string $term): Builder
    {
        $base = match ($type) {
            'news' => News::query()->published()
                ->select(['id', 'slug', 'category', 'title', 'summary', 'image', 'published_at', 'source_url', 'source_name', 'source_published_on']),
            'article' => GuideArticle::query()->visible()->with('category:id,name')
                ->select(['id', 'guide_category_id', 'slug', 'title', 'image', 'published_at']),
            'event' => Event::query()->visible()
                ->select(['id', 'event_category_id', 'slug', 'title', 'image', 'start_date', 'end_date', 'location']),
            'video' => GuideVideo::query()->visible()
                ->select(['id', 'guide_category_id', 'title', 'thumbnail', 'youtube_id', 'created_at']),
        };

        return $base->where(fn (Builder $q) => JsonSearch::orWhereLike($q, ['title->id', 'title->en'], $term));
    }

    private function ordered(string $type, Builder $query): Builder
    {
        return match ($type) {
            'news' => $query->orderByNewsDate(),
            'article' => $query->orderByDesc('published_at')->orderByDesc('id'),
            'event' => $query->orderByDesc('start_date')->orderByDesc('id'),
            'video' => $query->orderByDesc('id'),
        };
    }

    /** @return array<string, mixed> */
    private function item(string $type, Model $m, string $lang): array
    {
        $text = fn (?array $value) => (string) ($value[$lang] ?? $value['id'] ?? '');

        return match ($type) {
            'news' => [
                'type' => $type,
                'slug' => $m->slug,
                'title' => $text($m->title),
                'excerpt' => $text($m->summary) ?: null,
                'image' => $m->image_url,
                'date' => ($m->source_published_on ?? $m->published_at)?->toDateString(),
                'meta' => $m->source_name,
                // Berita dari media lain: dibuka di situs sumbernya
                'url' => $m->isExternal() ? $m->source_url : null,
            ],
            'article' => [
                'type' => $type,
                'slug' => $m->slug,
                'title' => $text($m->title),
                'excerpt' => null,
                'image' => $m->image_url,
                'date' => $m->published_at?->toDateString(),
                'meta' => is_array($m->category?->name) ? $text($m->category->name) : $m->category?->name,
                'url' => null,
            ],
            'event' => [
                'type' => $type,
                'slug' => $m->slug,
                'title' => $text($m->title),
                'excerpt' => null,
                'image' => $m->image_url,
                'date' => $m->start_date?->toDateString(),
                'meta' => $m->location,
                'url' => null,
            ],
            'video' => [
                'type' => $type,
                'slug' => null,
                'title' => $m->titleFor($lang),
                'excerpt' => null,
                'image' => $m->thumbnail_url,
                'date' => $m->created_at?->toDateString(),
                'meta' => null,
                'url' => $m->watch_url,
            ],
        };
    }
}
