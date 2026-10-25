<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Web Playbook (Panduan Praktis): dokumen PDF dengan cover.
 *
 * Cover di disk public (tampil di kartu). PDF di disk private: tidak punya URL publik, hanya dikirim lewat API —
 * dilihat siapa saja (Api\GuidePlaybookController::file), diunduh hanya member login (Api\Member\PlaybookController).
 */
class GuidePlaybook extends Model
{
    use HasFactory, TracksAuthor;

    public const COVER_DISK = 'public';

    public const PDF_DISK = 'local';

    public const DIRECTORY = 'guide-playbooks';

    /**
     * created_by / updated_by sengaja tidak fillable: diisi otomatis oleh TracksAuthor.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'cover_image',
        'file_pdf',
        'status',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['cover_url'];

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'status' => ContentStatus::class,
        ];
    }

    protected function coverUrl(): Attribute
    {
        return Attribute::get(fn () => $this->cover_image
            ? Storage::disk(self::COVER_DISK)->url($this->cover_image)
            : null);
    }

    /**
     * Title sesuai locale, fallback ke bahasa Indonesia.
     */
    public function titleFor(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return ($this->title[$locale] ?? null) ?: ($this->title['id'] ?? '');
    }

    /** Nama file saat diunduh / ditampilkan, dari judul (ID). */
    public function downloadName(): string
    {
        $slug = str($this->titleFor('id'))->slug()->limit(80, '')->toString();

        return ($slug ?: 'playbook-'.$this->id).'.pdf';
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Published);
    }

    /**
     * Cari di title ID & EN.
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('title->id', 'like', $like)
                ->orWhere('title->en', 'like', $like);
        });
    }
}
