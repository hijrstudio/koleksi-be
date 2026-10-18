<?php

namespace App\Http\Resources;

use App\Models\GuideInfographic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GuideInfographic
 */
class GuideInfographicCardResource extends JsonResource
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
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => [
                    'id' => $this->category->name['id'] ?? '',
                    'en' => ($this->category->name['en'] ?? null) ?: ($this->category->name['id'] ?? ''),
                ],
            ]),
            'image' => $this->image_url,
            'published_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
