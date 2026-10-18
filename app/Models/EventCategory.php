<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventCategory extends Model
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

    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Published);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Nama sesuai locale, fallback ke bahasa Indonesia.
     */
    public function nameFor(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->name[$locale] ?? $this->name['id'] ?? '';
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
