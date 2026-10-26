<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Sponsor extends Model
{
    use HasFactory, TracksAuthor;

    public const DISK = 'public';

    public const DIRECTORY = 'sponsors';

    /**
     * created_by / updated_by sengaja tidak fillable: diisi otomatis oleh TracksAuthor.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'logo',
        'url',
        'sort',
        'status',
    ];

    /**
     * URL logo ikut dikirim saat model diubah ke array/JSON (mis. untuk API FE).
     *
     * @var list<string>
     */
    protected $appends = ['logo_url'];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'status' => ContentStatus::class,
        ];
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->logo
            ? Storage::disk(self::DISK)->url($this->logo)
            : null);
    }

    public static function nextSort(): int
    {
        return (int) static::query()->max('sort') + 1;
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Published);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }

    /**
     * Cari di nama & URL sponsor.
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('url', 'like', $like));
    }
}
