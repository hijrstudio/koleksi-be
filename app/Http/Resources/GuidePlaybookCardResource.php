<?php

namespace App\Http\Resources;

use App\Models\GuidePlaybook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kartu Web Playbook. Path / URL PDF sengaja tidak dikirim: PDF hanya bisa diambil lewat endpoint file
 * (lihat) & download (member).
 *
 * @mixin GuidePlaybook
 */
class GuidePlaybookCardResource extends JsonResource
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
            'cover' => $this->cover_url,
            'published_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
