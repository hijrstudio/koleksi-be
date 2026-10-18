<?php

namespace App\Http\Resources;

use App\Models\HeroBanner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HeroBanner
 */
class HeroBannerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->file_type->value, // image | video
            'mime_type' => $this->mime_type,
            'url' => $this->file_url,
            'title' => [
                'id' => $this->title['id'] ?? '',
                'en' => $this->title['en'] ?? $this->title['id'] ?? '',
            ],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
