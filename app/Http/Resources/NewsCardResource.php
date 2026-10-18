<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsAuthor;
use App\Models\News;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kartu berita (list/homepage). `author` hanya dikirim bila relasi penulis sudah di-eager load
 * (list & detail berita), supaya homepage tidak memicu query tambahan.
 *
 * @mixin News
 */
class NewsCardResource extends JsonResource
{
    use FormatsAuthor;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => [
                'value' => $this->category->value, // news_release | ev_news | coverage
                'label' => [
                    'id' => $this->category->label('id'),
                    'en' => $this->category->label('en'),
                ],
            ],
            'title' => [
                'id' => $this->title['id'] ?? '',
                'en' => ($this->title['en'] ?? null) ?: ($this->title['id'] ?? ''),
            ],
            'author' => $this->when($this->authorLoaded(), fn () => $this->authorLabel()),
            'slug' => $this->slug,
            'image' => $this->image_url,
            'published_at' => ($this->published_at ?? $this->created_at)?->toIso8601String(),
        ];
    }
}
