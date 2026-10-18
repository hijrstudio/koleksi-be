<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    use HasFactory, TracksAuthor;

    /**
     * created_by / updated_by sengaja tidak fillable: diisi otomatis oleh TracksAuthor.
     *
     * @var list<string>
     */
    protected $fillable = [
        'question',
        'answer',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'question' => 'array',
            'answer' => 'array',
            'sort_order' => 'integer',
            'status' => ContentStatus::class,
        ];
    }

    /**
     * Nilai kolom json sesuai locale, fallback ke bahasa Indonesia.
     */
    public function translate(string $field, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->{$field}[$locale] ?? $this->{$field}['id'] ?? '';
    }

    public static function nextSortOrder(): int
    {
        return (int) static::query()->max('sort_order') + 1;
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Published);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Cari di pertanyaan ID & EN.
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('question->id', 'like', $like)
                ->orWhere('question->en', 'like', $like);
        });
    }
}
