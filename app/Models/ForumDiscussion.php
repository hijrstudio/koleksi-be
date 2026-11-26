<?php

namespace App\Models;

use App\Models\Concerns\HasForumAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Diskusi forum. Ditulis member (langsung terbit) atau admin CMS. Admin bisa menyembunyikan (status hidden),
 * menyematkan (pinned) dan mengunci komentar (locked). Counter komentar/dilihat/rating diperbarui ForumService.
 */
class ForumDiscussion extends Model
{
    use HasForumAuthor, SoftDeletes;

    public const PUBLISHED = 'published';

    public const HIDDEN = 'hidden';

    public const STATUSES = [self::PUBLISHED, self::HIDDEN];

    public const IMAGE_DISK = 'public';

    public const IMAGE_DIR = 'forum/discussions';

    public const TITLE_MAX = 150;

    public const BODY_MAX = 10000;

    protected $fillable = ['category_id', 'title', 'body', 'image', 'status', 'is_pinned', 'is_locked'];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'is_locked' => 'boolean',
            'comments_count' => 'integer',
            'views_count' => 'integer',
            'rating_sum' => 'integer',
            'rating_count' => 'integer',
            'last_activity_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $d) {
            $d->slug ??= self::makeSlug($d->title);
            $d->last_activity_at ??= now();
        });
    }

    /** "Apakah EV Pilihan Tepat?" → "apakah-ev-pilihan-tepat-k3x9qa" */
    public static function makeSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title), 150, '') ?: 'diskusi';

        return $base.'-'.Str::lower(Str::random(6));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ForumCategory::class, 'category_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ForumComment::class, 'discussion_id');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(ForumRating::class, 'discussion_id');
    }

    public function scopePublished(Builder $q): void
    {
        $q->where('status', self::PUBLISHED);
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    /** Rata-rata rating 0–5 (1 desimal). */
    public function ratingAverage(): float
    {
        return $this->rating_count > 0 ? round($this->rating_sum / $this->rating_count, 1) : 0.0;
    }

    /** null = tanpa gambar sampul (website memakai gambar default-nya sendiri). */
    public function imageUrl(): ?string
    {
        return $this->image ? Storage::disk(self::IMAGE_DISK)->url($this->image) : null;
    }
}
