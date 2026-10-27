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
        'summary',
        'content',
        'image',
        'status',
        // Hanya berita dari media lain (NewsCategory::isExternal())
        'source_url',
        'source_name',
        'source_author',
        'source_published_on',
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
            'summary' => 'array',
            'content' => 'array',
            'status' => ContentStatus::class,
            'published_at' => 'datetime',
            'source_published_on' => 'date',
        ];
    }

    /** Berita dari media lain (kategori Berita EV): website membuka source_url di tab baru. */
    public function isExternal(): bool
    {
        return $this->category?->isExternal() ?? false;
    }

    protected static function slugFallback(): string
    {
        return 'berita';
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Published);
    }

    /**
     * Urutan tampil di website: Berita EV menurut tanggal berita di media sumber, kategori lain menurut
     * tanggal publish di KOLEKSI — juga saat semua kategori dicampur (list tanpa filter, homepage).
     */
    public function scopeOrderByNewsDate(Builder $query, string $direction = 'desc'): void
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc'; // whitelist: masuk ke SQL mentah

        $query->orderByRaw("COALESCE(source_published_on, published_at) {$direction}")
            ->orderBy('published_at', $direction)
            ->orderBy('id', $direction);
    }
}
