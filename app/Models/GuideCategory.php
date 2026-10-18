<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GuideCategory extends Model
{
    use HasFactory, TracksAuthor;

    /**
     * created_by / updated_by sengaja tidak fillable: diisi otomatis oleh TracksAuthor.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'status' => ContentStatus::class,
        ];
    }

    public function articles(): HasMany
    {
        return $this->hasMany(GuideArticle::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(GuideVideo::class);
    }

    public function infographics(): HasMany
    {
        return $this->hasMany(GuideInfographic::class);
    }

    /**
     * Kategori yang masih dipakai artikel / video / infografis tidak boleh dihapus.
     */
    public function isInUse(): bool
    {
        return $this->articles()->exists() || $this->videos()->exists() || $this->infographics()->exists();
    }

    /**
     * Nama sesuai locale, fallback ke bahasa Indonesia.
     */
    public function nameFor(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->name[$locale] ?? $this->name['id'] ?? '';
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Published);
    }

    /**
     * Cari di nama ID & EN.
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('name->id', 'like', $like)
                ->orWhere('name->en', 'like', $like);
        });
    }
}
