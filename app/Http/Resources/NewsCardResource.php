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
 * Berita EV (category.external = true) adalah tautan ke media lain: `source` berisi link (dibuka di tab baru),
 * nama media, penulis & tanggal berita; `author` = penulis/nama media; `summary` = ringkasan. Kategori lain:
 * `summary` & `source` null.
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
        $external = $this->isExternal();
        $localized = fn (?array $text) => [
            'id' => $text['id'] ?? '',
            'en' => ($text['en'] ?? null) ?: ($text['id'] ?? ''),
        ];

        return [
            'id' => $this->id,
            'category' => [
                'value' => $this->category->value, // news_release | ev_news | coverage
                'label' => [
                    'id' => $this->category->label('id'),
                    'en' => $this->category->label('en'),
                ],
                'external' => $external,
            ],
            'title' => $localized($this->title),
            'summary' => $external ? $localized($this->summary) : null,
            'author' => $this->when(
                $external || $this->authorLoaded(),
                fn () => $external ? ($this->source_author ?: $this->source_name) : $this->authorLabel(),
            ),
            'source' => $external ? [
                'url' => $this->source_url, // http/https, divalidasi di CMS
                'name' => $this->source_name,
                'author' => $this->source_author,
                'published_on' => $this->source_published_on?->toDateString(),
            ] : null,
            'slug' => $this->slug,
            'image' => $this->image_url,
            'published_at' => ($this->published_at ?? $this->created_at)?->toIso8601String(),
        ];
    }
}
