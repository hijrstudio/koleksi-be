<?php

namespace App\Models\Concerns;

use App\Enums\ContentStatus;
use App\Support\ContentImages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Storage;

/**
 * Konten bilingual (title & content json {"id","en"}) dengan gambar utama, gambar di dalam konten,
 * status draft/published, dan published_at yang terisi saat pertama kali dipublish.
 *
 * Model pemakai wajib punya konstanta DISK dan CONTENT_IMAGE_DIRECTORY.
 */
trait HasRichContent
{
    public static function bootHasRichContent(): void
    {
        static::saving(function ($model) {
            if ($model->status === ContentStatus::Published && $model->published_at === null) {
                $model->published_at = now();
            }
        });
    }

    /**
     * Nilai kolom json sesuai locale, fallback ke bahasa Indonesia.
     */
    public function translate(string $field, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->{$field}[$locale] ?? $this->{$field}['id'] ?? '';
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image
            ? Storage::disk(static::DISK)->url($this->image)
            : null);
    }

    /**
     * URL publik folder gambar konten model ini.
     */
    public static function contentImageBaseUrl(): string
    {
        return ContentImages::baseUrl(static::CONTENT_IMAGE_DIRECTORY);
    }

    /**
     * @param  array<string, ?string>|null  $content
     * @return list<string>
     */
    public static function contentImagePaths(?array $content): array
    {
        return ContentImages::pathsIn($content);
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
