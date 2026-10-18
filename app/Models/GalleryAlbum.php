<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class GalleryAlbum extends Model
{
    use HasFactory, TracksAuthor;

    public const DISK = 'public';

    /** Cover album */
    public const DIRECTORY = 'gallery/covers';

    /**
     * created_by / updated_by sengaja tidak fillable: diisi otomatis oleh TracksAuthor.
     *
     * @var list<string>
     */
    protected $fillable = [
        'image',
        'title',
        'gallery_date',
        'status',
    ];

    /**
     * URL cover ikut dikirim saat model diubah ke array/JSON (mis. untuk API FE).
     *
     * @var list<string>
     */
    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'gallery_date' => 'date',
            'status' => ContentStatus::class,
        ];
    }

    public function photos(): HasMany
    {
        return $this->hasMany(GalleryPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image
            ? Storage::disk(self::DISK)->url($this->image)
            : null);
    }

    /**
     * Title sesuai locale, fallback ke bahasa Indonesia.
     */
    public function titleFor(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->title[$locale] ?? $this->title['id'] ?? '';
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
