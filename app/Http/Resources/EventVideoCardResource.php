<?php

namespace App\Http\Resources;

use App\Models\EventVideo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kartu video kegiatan. Bentuk sama dengan GuideVideoCardResource tanpa kategori.
 *
 * @mixin EventVideo
 */
class EventVideoCardResource extends JsonResource
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
                'en' => ($this->title['en'] ?? null) ?: ($this->title['id'] ?? ''),
            ],
            'image' => $this->thumbnail_url,
            'youtube_id' => $this->youtube_id,
            'published_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
