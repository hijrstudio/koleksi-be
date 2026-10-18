<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SpkluOperator extends Model
{
    use HasFactory, TracksAuthor;

    public const DISK = 'public';

    public const DIRECTORY = 'spklu-operators';

    /**
     * created_by / updated_by sengaja tidak fillable: diisi otomatis oleh TracksAuthor.
     *
     * @var list<string>
     */
    protected $fillable = [
        'nama_operator',
        'brand_spklu',
        'logo',
        'aplikasi',
        'link_playstore',
        'link_appstore',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
        ];
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->logo
            ? Storage::disk(self::DISK)->url($this->logo)
            : null);
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Published);
    }

    /**
     * Urut nama brand; id sebagai pemecah seri supaya urutan antar halaman stabil.
     */
    public function scopeOrdered(Builder $query, string $direction = 'asc'): void
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        $query->orderBy('brand_spklu', $direction)->orderBy('id', $direction);
    }

    /**
     * Cari di brand, nama operator & aplikasi.
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(fn (Builder $q) => $q
            ->where('brand_spklu', 'like', $like)
            ->orWhere('nama_operator', 'like', $like)
            ->orWhere('aplikasi', 'like', $like));
    }
}
