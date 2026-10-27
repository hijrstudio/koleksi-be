<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasYoutubeVideo;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Video kegiatan (website: /kegiatan/galeri/video). Tanpa kategori.
 */
class EventVideo extends Model
{
    use HasFactory, HasYoutubeVideo, TracksAuthor;

    public const DISK = 'public';

    public const DIRECTORY = 'event-videos';

    /**
     * created_by / updated_by sengaja tidak fillable: diisi otomatis oleh TracksAuthor.
     *
     * @var list<string>
     */
    protected $fillable = [
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

    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Published);
    }
}
