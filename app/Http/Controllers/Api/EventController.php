<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EventIndexRequest;
use App\Http\Resources\EventCardResource;
use App\Models\Event;
use App\Models\EventCategory;
use App\Support\JsonSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kalender kegiatan untuk website. Hanya event visible (event & kategorinya published).
 */
class EventController extends Controller
{
    private const RELATED_LIMIT = 4;

    private const CARD_COLUMNS = [
        'id', 'event_category_id', 'title', 'slug', 'image', 'start_date', 'end_date', 'location',
    ];

    /**
     * List kegiatan: tab fase (On Plan / On Going / Done, dihitung dari start_date & end_date),
     * pencarian (judul ID/EN & lokasi), filter kategori, paginasi bernomor.
     * Tanpa phase: semua kegiatan, start_date terbaru dulu. COUNT + data = 2 query.
     */
    public function index(EventIndexRequest $request): JsonResponse
    {
        $phase = $request->phase();
        $search = trim((string) $request->validated('search'));
        $categoryId = $request->validated('category');

        $events = Event::query()
            ->select(self::CARD_COLUMNS)
            ->visible()
            ->when($categoryId, fn (Builder $q) => $q->where('event_category_id', $categoryId))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($search) {
                JsonSearch::orWhereLike($q, ['title->id', 'title->en'], $search);
                $q->orWhereRaw('lower(location) like ?', [JsonSearch::likePattern($search)]);
            }))
            ->when(
                $phase,
                fn (Builder $q) => $q->phase($phase),
                fn (Builder $q) => $q->orderByDesc('start_date'),
            )
            ->orderByDesc('id')
            ->paginate($request->perPage());

        return response()->json([
            'data' => EventCardResource::collection($events->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    /**
     * Detail kegiatan + kegiatan terkait: kategori sama dulu, lalu dipenuhi kegiatan lain
     * sampai 4 (start_date terbaru dulu). Kategori di-eager load: 3 query.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $event = Event::query()
            ->select([...self::CARD_COLUMNS, 'description', 'latitude', 'longitude', 'registration_info'])
            ->visible()
            ->with('category:id,name')
            ->where('slug', $slug)
            ->firstOrFail();

        $related = Event::query()
            ->select(self::CARD_COLUMNS)
            ->visible()
            ->whereKeyNot($event->id)
            ->orderByRaw('case when event_category_id = ? then 0 else 1 end', [$event->event_category_id])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->limit(self::RELATED_LIMIT)
            ->get();

        return response()->json([
            'data' => [
                ...(new EventCardResource($event))->resolve($request),
                'category' => [
                    'id' => $event->category->id,
                    'name' => $this->localized($event->category->name),
                ],
                'description' => $this->localized($event->description), // HTML tersanitasi
                'registration_info' => $event->registration_info ? $this->localized($event->registration_info) : null,
                'latitude' => $event->latitude,
                'longitude' => $event->longitude,
                'maps_url' => $event->maps_url,
            ],
            'related' => EventCardResource::collection($related)->resolve($request),
        ]);
    }

    /**
     * Kategori untuk filter: semua kategori kegiatan yang published.
     */
    public function categories(): JsonResponse
    {
        $categories = EventCategory::query()
            ->select(['id', 'name'])
            ->published()
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $categories->map(fn (EventCategory $category) => [
                'id' => $category->id,
                'name' => $this->localized($category->name),
            ]),
        ]);
    }

    /**
     * @param  array<string, ?string>|null  $value
     * @return array{id: string, en: string}
     */
    private function localized(?array $value): array
    {
        $id = $value['id'] ?? '';

        return ['id' => $id, 'en' => ($value['en'] ?? null) ?: $id];
    }
}
