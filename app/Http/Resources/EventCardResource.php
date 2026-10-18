<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kartu kegiatan (list/homepage).
 *
 * @mixin Event
 */
class EventCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => [
                'id' => $this->title['id'] ?? '',
                'en' => $this->title['en'] ?? $this->title['id'] ?? '',
            ],
            'slug' => $this->slug,
            'image' => $this->image_url,
            'start_date' => $this->start_date?->toDateString(), // Y-m-d, tanpa jam
            'end_date' => $this->end_date?->toDateString(),
            'location' => $this->location,
            'phase' => $this->phase?->value,
        ];
    }
}
