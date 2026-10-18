<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class GuideInfographic extends Model
{
    use HasFactory, TracksAuthor;

    public const DISK = 'public';

    public const DIRECTORY = 'guide-infographics';

    /**
     * created_by / updated_by sengaja tidak fillable: diisi otomatis oleh TracksAuthor.
     *
     * @var list<string>
     */
    protected $fillable = [
        'guide_category_id',
        'title',
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
            'title' => 'array',
            'status' => ContentStatus::class,
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GuideCategory::class, 'guide_category_id');
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
     * Infografis yang boleh tampil di website: infografis & kategorinya sama-sama published.
     */
    public function scopeVisible(Builder $query): void
    {
        $query->published()->whereHas('category', fn (Builder $q) => $q->published());
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
