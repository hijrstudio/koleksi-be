<?php

namespace App\Http\Resources;

use App\Models\ForumDiscussion;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Diskusi forum untuk website. Daftar: ringkasan isi (excerpt). Detail (`detail` = true): isi lengkap,
 * rating member yang login & boleh tidaknya memberi rating.
 *
 * @mixin ForumDiscussion
 */
class ForumDiscussionResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): static
    {
        $this->detail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $member = $request->user();

        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'excerpt' => Str::limit(preg_replace('/\s+/u', ' ', $this->body) ?? '', 160),
            'image' => $this->imageUrl(),
            'category' => $this->whenLoaded('category', fn () => ['slug' => $this->category->slug, 'name' => $this->category->name]),
            'author' => $this->authorPayload(),
            'is_pinned' => $this->is_pinned,
            'is_locked' => $this->is_locked,
            'comments_count' => $this->comments_count,
            'views_count' => $this->views_count,
            'rating' => ['average' => $this->ratingAverage(), 'count' => $this->rating_count],
            'created_at' => $this->created_at?->toIso8601String(),
            $this->mergeWhen($this->detail, fn () => [
                'body' => $this->body,
                'is_mine' => $member instanceof Member && $this->isAuthoredBy($member),
                'my_rating' => $member instanceof Member
                    ? $this->ratings()->where('member_id', $member->id)->value('rating')
                    : null,
            ]),
        ];
    }
}
