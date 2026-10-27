<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Storage;

/**
 * Video YouTube (video edukasi, video kegiatan): kolom `title` (json ID/EN), `youtube_id` (11 karakter) &
 * `thumbnail` (path upload, opsional). Model wajib punya konstanta DISK untuk thumbnail.
 */
trait HasYoutubeVideo
{
    public const YOUTUBE_ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    /** Host YouTube yang diterima saat admin menempelkan URL */
    private const YOUTUBE_HOSTS = [
        'youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com',
        'youtu.be', 'www.youtu.be', 'youtube-nocookie.com', 'www.youtube-nocookie.com',
    ];

    public function initializeHasYoutubeVideo(): void
    {
        // URL turunan ikut dikirim saat model diubah ke array/JSON (mis. untuk API FE).
        $this->append(['thumbnail_url', 'embed_url', 'watch_url']);
    }

    /**
     * Ambil ID video dari URL YouTube (watch, youtu.be, shorts, embed, live) atau ID polos.
     * Mengembalikan null bila bukan video YouTube yang valid.
     */
    public static function parseYoutubeId(?string $input): ?string
    {
        $input = trim((string) $input);

        if ($input === '') {
            return null;
        }

        if (preg_match(self::YOUTUBE_ID_PATTERN, $input)) {
            return $input;
        }

        if (! preg_match('#^https?://#i', $input)) {
            $input = 'https://'.$input; // mis. "youtu.be/xxxx" tanpa skema
        }

        $parts = parse_url($input);
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($host, self::YOUTUBE_HOSTS, true)) {
            return null;
        }

        $path = trim($parts['path'] ?? '', '/');

        if (str_ends_with($host, 'youtu.be')) {
            $candidate = explode('/', $path)[0];
        } elseif ($path === 'watch') {
            parse_str($parts['query'] ?? '', $query);
            $candidate = is_string($query['v'] ?? null) ? $query['v'] : '';
        } elseif (preg_match('#^(?:embed|shorts|live|v)/([^/]+)#', $path, $matches)) {
            $candidate = $matches[1];
        } else {
            return null;
        }

        return preg_match(self::YOUTUBE_ID_PATTERN, $candidate) ? $candidate : null;
    }

    /**
     * Thumbnail upload, atau thumbnail bawaan YouTube bila tidak diupload.
     */
    protected function thumbnailUrl(): Attribute
    {
        return Attribute::get(fn () => $this->thumbnail
            ? Storage::disk(static::DISK)->url($this->thumbnail)
            : ($this->youtube_id ? "https://i.ytimg.com/vi/{$this->youtube_id}/hqdefault.jpg" : null));
    }

    /** Untuk iframe popup di FE (privacy-enhanced mode) */
    protected function embedUrl(): Attribute
    {
        return Attribute::get(fn () => $this->youtube_id
            ? "https://www.youtube-nocookie.com/embed/{$this->youtube_id}"
            : null);
    }

    protected function watchUrl(): Attribute
    {
        return Attribute::get(fn () => $this->youtube_id
            ? "https://www.youtube.com/watch?v={$this->youtube_id}"
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

    /**
     * Cari di title ID & EN, atau ID YouTube persis.
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $youtubeId = static::parseYoutubeId($term);

        $query->where(function (Builder $q) use ($like, $youtubeId) {
            $q->where('title->id', 'like', $like)
                ->orWhere('title->en', 'like', $like)
                ->when($youtubeId, fn (Builder $q) => $q->orWhere('youtube_id', $youtubeId));
        });
    }
}
