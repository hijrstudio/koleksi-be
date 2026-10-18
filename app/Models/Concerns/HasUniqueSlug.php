<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Slug unik untuk URL website, otomatis dari title Indonesia bila belum diisi.
 * Slug tidak ikut berubah saat title diedit, agar link lama tetap jalan.
 */
trait HasUniqueSlug
{
    public const MAX_SLUG_LENGTH = 255;

    /** Sisa ruang untuk akhiran -2, -3, ... */
    public const MAX_SLUG_BASE_LENGTH = 240;

    public static function bootHasUniqueSlug(): void
    {
        static::saving(function ($model) {
            $model->slug ??= static::uniqueSlug($model->title['id'] ?? '', $model->getKey());
        });
    }

    /** Slug cadangan bila title tidak menghasilkan slug (mis. hanya simbol) */
    protected static function slugFallback(): string
    {
        return 'konten';
    }

    /**
     * Slug dari teks (title / slug ketikan); ditambah akhiran -2, -3, ... bila sudah dipakai data lain.
     */
    public static function uniqueSlug(string $text, ?int $ignoreId = null): string
    {
        $base = trim(Str::limit(Str::slug($text), self::MAX_SLUG_BASE_LENGTH, ''), '-') ?: static::slugFallback();
        $slug = $base;

        for ($i = 2; static::slugTaken($slug, $ignoreId); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private static function slugTaken(string $slug, ?int $ignoreId): bool
    {
        return static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }
}
