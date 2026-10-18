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

class GuideVideo extends Model
{
    use HasFactory, TracksAuthor;

    public const DISK = 'public';

    public const DIRECTORY = 'guide-videos';

    public const YOUTUBE_ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    /** Host YouTube yang diterima saat admin menempelkan URL */
    private const YOUTUBE_HOSTS = [
        'youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com',
        'youtu.be', 'www.youtu.be', 'youtube-nocookie.com', 'www.youtube-nocookie.com',
    ];

    /**
     * created_by / updated_by sengaja tidak fillable: diisi otomatis oleh TracksAuthor.
     *
     * @var list<string>
     */
    protected $fillable = [
        'guide_category_id',
        'title',
        'thumbnail',
        'youtube_id',
        'status',
    ];

    /**
     * URL turunan ikut dikirim saat model diubah ke array/JSON (mis. untuk API FE).
     *
     * @var list<string>
     */
    protected $appends = ['thumbnail_url', 'embed_url', 'watch_url'];

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
            ? Storage::disk(self::DISK)->url($this->thumbnail)
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

    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Published);
    }

    /**
     * Video yang boleh tampil di website: video & kategorinya sama-sama published.
     */
    public function scopeVisible(Builder $query): void
    {
        $query->published()->whereHas('category', fn (Builder $q) => $q->published());
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
