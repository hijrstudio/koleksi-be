<?php

namespace App\Models;

use App\Models\Concerns\HasForumAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Komentar diskusi. parent_id kosong = komentar utama; balasan bersarang maks MAX_DEPTH (0, 1, 2 → 3 tingkat).
 * Balasan pada tingkat terdalam ditempel ke induk tingkat terdalam, supaya tampilan tidak menjorok tanpa batas.
 */
class ForumComment extends Model
{
    use HasForumAuthor, SoftDeletes;

    public const PUBLISHED = 'published';

    public const HIDDEN = 'hidden';

    public const MAX_DEPTH = 2;

    public const BODY_MAX = 2000;

    protected $fillable = ['body', 'status'];

    protected function casts(): array
    {
        return ['depth' => 'integer'];
    }

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(ForumDiscussion::class, 'discussion_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopePublished(Builder $q): void
    {
        $q->where('status', self::PUBLISHED);
    }
}
