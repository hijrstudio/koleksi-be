<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasYoutubeVideo;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuideVideo extends Model
{
    use HasFactory, HasYoutubeVideo, TracksAuthor;

    public const DISK = 'public';

    public const DIRECTORY = 'guide-videos';

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
}
