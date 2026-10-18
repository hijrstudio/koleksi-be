<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Enums\NewsCategory;
use App\Models\Concerns\HasAuthor;
use App\Models\Concerns\HasRichContent;
use App\Models\Concerns\HasUniqueSlug;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class News extends Model
{
    use HasAuthor, HasFactory, HasRichContent, HasUniqueSlug, TracksAuthor;

    public const DISK = 'public';

    public const DIRECTORY = 'news';

    /** Gambar yang disisipkan di dalam konten (rich editor) */
    public const CONTENT_IMAGE_DIRECTORY = 'news/content';

    protected $table = 'news';

    /**
     * Penulis (author_*), slug, dan created_by / updated_by sengaja tidak fillable:
     * diisi eksplisit oleh controller / otomatis oleh model.
     *
     * @var list<string>
     */
    protected $fillable = [
        'category',
        'title',
        'content',
        'image',
        'status',
    ];

    /**
     * URL gambar ikut dikirim saat model diubah ke array/JSON (mis. untuk API FE).
     *
     * @var list<string>
     */
    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return [
            'category' => NewsCategory::class,
            'title' => 'array',
            'content' => 'array',
            'status' => ContentStatus::class,
            'published_at' => 'datetime',
        ];
    }

    protected static function slugFallback(): string
    {
        return 'berita';
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Published);
    }
}
