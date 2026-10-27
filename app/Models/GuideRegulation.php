<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Dokumen Regulasi (Panduan Praktis): PDF peraturan. Judul satu bahasa (nama resmi dokumen).
 *
 * PDF di disk private: tidak punya URL publik, hanya dikirim lewat API (Api\GuideRegulationController::file)
 * selama dokumen published.
 */
class GuideRegulation extends Model
{
    use HasFactory, TracksAuthor;

    public const PDF_DISK = 'local';

    public const DIRECTORY = 'guide-regulations';

    /**
     * created_by / updated_by sengaja tidak fillable: diisi otomatis oleh TracksAuthor.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'file_pdf',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
        ];
    }

    /** Nama file saat dibuka / diunduh, dari judul. */
    public function downloadName(): string
    {
        $slug = str($this->title)->slug()->limit(80, '')->toString();

        return ($slug ?: 'dokumen-regulasi-'.$this->id).'.pdf';
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Published);
    }

    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $query->where('title', 'like', '%'.addcslashes($term, '%_\\').'%');
    }
}
