<?php

namespace App\Http\Resources;

use App\Models\GalleryAlbum;
use App\Models\GalleryPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Album galeri + daftar foto (urut sort_order) untuk lightbox di website.
 *
 * @mixin GalleryAlbum
 */
class GalleryAlbumResource extends JsonResource
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
            'cover' => $this->image_url,
            'gallery_date' => $this->gallery_date?->toDateString(), // Y-m-d, tanpa jam
            'photos' => $this->whenLoaded('photos', fn () => $this->photos
                ->map(fn (GalleryPhoto $photo) => [
                    'id' => $photo->id,
                    'url' => $photo->image_url,
                ])
                ->values()),
        ];
    }
}
