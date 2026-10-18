<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsAuthor;
use App\Models\GuideArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kartu artikel panduan (list/homepage). Bentuk sama dengan GuideVideoCardResource
 * supaya FE bisa render keduanya dalam satu list.
 *
 * @mixin GuideArticle
 */
class GuideArticleCardResource extends JsonResource
{
    use FormatsAuthor;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'article',
            'title' => [
                'id' => $this->title['id'] ?? '',
                'en' => $this->title['en'] ?? $this->title['id'] ?? '',
            ],
            'author' => $this->authorLabel(),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => [
                    'id' => $this->category->name['id'] ?? '',
                    'en' => ($this->category->name['en'] ?? null) ?: ($this->category->name['id'] ?? ''),
                ],
            ]),
            'image' => $this->image_url,
            'slug' => $this->slug,
            'youtube_id' => null,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
