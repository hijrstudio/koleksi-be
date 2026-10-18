<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Enums\MediaType;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class HeroBanner extends Model
{
    use HasFactory, TracksAuthor;

    public const DISK = 'public';

    public const DIRECTORY = 'hero-banners';

    /**
     * created_by / updated_by sengaja tidak fillable: diisi otomatis oleh TracksAuthor.
     *
     * @var list<string>
     */
    protected $fillable = [
        'file_path',
        'file_type',
        'mime_type',
        'title',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'status' => ContentStatus::class,
            'file_type' => MediaType::class,
        ];
    }

    /**
     * Title sesuai locale, fallback ke bahasa Indonesia.
     */
    public function titleFor(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->title[$locale] ?? $this->title['id'] ?? '';
    }

    protected function fileUrl(): Attribute
    {
        return Attribute::get(fn () => $this->file_path
            ? Storage::disk(self::DISK)->url($this->file_path)
            : null);
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Published);
    }

    /**
     * Cari di title ID & EN.
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('title->id', 'like', $like)
                ->orWhere('title->en', 'like', $like);
        });
    }
}
