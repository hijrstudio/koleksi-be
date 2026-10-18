<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Enums\EventPhase;
use App\Models\Concerns\HasUniqueSlug;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class Event extends Model
{
    use HasFactory, HasUniqueSlug, TracksAuthor;

    public const DISK = 'public';

    public const DIRECTORY = 'events';

    /**
     * slug & created_by / updated_by sengaja tidak fillable: diisi eksplisit / otomatis.
     *
     * @var list<string>
     */
    protected $fillable = [
        'event_category_id',
        'title',
        'image',
        'start_date',
        'end_date',
        'description',
        'location',
        'latitude',
        'longitude',
        'registration_info',
        'status',
    ];

    /**
     * Nilai turunan ikut dikirim saat model diubah ke array/JSON (mis. untuk API FE).
     *
     * @var list<string>
     */
    protected $appends = ['image_url', 'phase', 'maps_url'];

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'description' => 'array',
            'registration_info' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
            'latitude' => 'float',
            'longitude' => 'float',
            'status' => ContentStatus::class,
        ];
    }

    protected static function booted(): void
    {
        // Event satu hari: end_date mengikuti start_date
        static::saving(function (Event $event) {
            $event->end_date ??= $event->start_date;
        });
    }

    /**
     * Simpan tanggal murni Y-m-d (tanpa jam) agar perbandingan per hari & index tanggal konsisten
     * di semua database. Saat dibaca tetap Carbon (lewat cast "date").
     */
    protected function startDate(): Attribute
    {
        return Attribute::set(fn ($value) => $value ? Carbon::parse($value)->toDateString() : null);
    }

    protected function endDate(): Attribute
    {
        return Attribute::set(fn ($value) => $value ? Carbon::parse($value)->toDateString() : null);
    }

    protected static function slugFallback(): string
    {
        return 'event';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'event_category_id');
    }

    /**
     * Fase saat ini (akan datang / berlangsung / selesai), dihitung dari tanggal, bukan disimpan.
     */
    protected function phase(): Attribute
    {
        return Attribute::get(fn () => $this->start_date && $this->end_date
            ? EventPhase::for($this->start_date, $this->end_date)
            : null);
    }

    /** Link Google Maps ke titik lokasi (null bila koordinat belum diisi) */
    protected function mapsUrl(): Attribute
    {
        return Attribute::get(fn () => $this->hasCoordinates()
            ? "https://www.google.com/maps?q={$this->latitude},{$this->longitude}"
            : null);
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image
            ? Storage::disk(self::DISK)->url($this->image)
            : null);
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Nilai kolom json sesuai locale, fallback ke bahasa Indonesia.
     */
    public function translate(string $field, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->{$field}[$locale] ?? $this->{$field}['id'] ?? '';
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Published);
    }

    /**
     * Event yang boleh tampil di website: event & kategorinya sama-sama published.
     */
    public function scopeVisible(Builder $query): void
    {
        $query->published()->whereHas('category', fn (Builder $q) => $q->published());
    }

    /**
     * Filter per tab + urutan yang wajar untuk tab tersebut:
     *  - akan datang: yang paling dekat dulu
     *  - berlangsung: yang paling cepat berakhir dulu
     *  - selesai: yang paling baru selesai dulu
     */
    public function scopePhase(Builder $query, EventPhase $phase, mixed $today = null): void
    {
        $today = ($today ? Carbon::parse($today) : now())->toDateString();

        match ($phase) {
            EventPhase::Upcoming => $query->where('start_date', '>', $today)->orderBy('start_date'),
            EventPhase::Ongoing => $query->where('start_date', '<=', $today)->where('end_date', '>=', $today)->orderBy('end_date'),
            EventPhase::Done => $query->where('end_date', '<', $today)->orderByDesc('end_date'),
        };
    }

    /**
     * Cari di title ID & EN, atau lokasi.
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('title->id', 'like', $like)
                ->orWhere('title->en', 'like', $like)
                ->orWhere('location', 'like', $like);
        });
    }
}
