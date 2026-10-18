<?php

namespace App\Http\Resources;

use App\Models\GuideVideo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kartu video edukasi (list/homepage). Bentuk sama dengan GuideArticleCardResource.
 *
 * @mixin GuideVideo
 */
class GuideVideoCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'video',
            'title' => [
                'id' => $this->title['id'] ?? '',
                'en' => $this->title['en'] ?? $this->title['id'] ?? '',
            ],
            'author' => null, // video tidak punya penulis; FE menyembunyikan "Oleh:"
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => [
                    'id' => $this->category->name['id'] ?? '',
                    'en' => ($this->category->name['en'] ?? null) ?: ($this->category->name['id'] ?? ''),
                ],
            ]),
            'image' => $this->thumbnail_url,
            'slug' => null,
            'youtube_id' => $this->youtube_id,
            'published_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
