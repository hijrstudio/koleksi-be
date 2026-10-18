<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasAuthor;
use App\Models\Concerns\HasRichContent;
use App\Models\Concerns\HasUniqueSlug;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuideArticle extends Model
{
    use HasAuthor, HasFactory, HasRichContent, HasUniqueSlug, TracksAuthor;

    public const DISK = 'public';

    public const DIRECTORY = 'guide-articles';

    /** Gambar yang disisipkan di dalam konten (rich editor) */
    public const CONTENT_IMAGE_DIRECTORY = 'guide-articles/content';

    /**
     * Penulis (author_*), slug, views, dan created_by / updated_by sengaja tidak fillable:
     * diisi eksplisit oleh controller / otomatis oleh model.
     *
     * @var list<string>
     */
    protected $fillable = [
        'guide_category_id',
        'title',
        'content',
        'image',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'content' => 'array',
            'status' => ContentStatus::class,
            'published_at' => 'datetime',
            'views' => 'integer',
        ];
    }

    protected static function slugFallback(): string
    {
        return 'artikel';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GuideCategory::class, 'guide_category_id');
    }

    /**
     * Artikel yang boleh tampil di website: artikel & kategorinya sama-sama published.
     */
    public function scopeVisible(Builder $query): void
    {
        $query->published()->whereHas('category', fn (Builder $q) => $q->published());
    }
}
