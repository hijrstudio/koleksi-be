<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventCardResource;
use App\Models\Event;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Kegiatan terbaru untuk homepage: event (dan kategorinya) published, start_date terbaru dulu.
 * Satu query tanpa eager load (tidak N+1); filter kategori lewat subquery whereHas,
 * urutan memakai index (status, start_date).
 */
class LatestEventController extends Controller
{
    private const LIMIT = 4;

    public function __invoke(): AnonymousResourceCollection
    {
        $events = Event::query()
            ->select(['id', 'event_category_id', 'title', 'slug', 'image', 'start_date', 'end_date', 'location'])
            ->visible()
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return EventCardResource::collection($events);
    }
}
