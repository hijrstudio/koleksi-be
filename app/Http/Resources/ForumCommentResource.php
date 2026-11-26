<?php

namespace App\Http\Resources;

use App\Models\ForumComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Komentar forum + balasannya (relasi `children` yang sudah disusun ForumController). Komentar yang
 * disembunyikan admin tetap tampil sebagai penanda (tanpa isi & penulis) supaya balasan di bawahnya tetap terbaca.
 *
 * @mixin ForumComment
 */
class ForumCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hidden = $this->status !== ForumComment::PUBLISHED;

        return [
            'id' => $this->id,
            'hidden' => $hidden,
            'body' => $hidden ? null : $this->body,
            'author' => $hidden ? null : $this->authorPayload(),
            'depth' => $this->depth,
            'created_at' => $this->created_at?->toIso8601String(),
            'replies' => self::collection($this->relationLoaded('children') ? $this->getRelation('children') : collect()),
        ];
    }
}
