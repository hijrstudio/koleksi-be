<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManagementPeriod extends Model
{
    use HasFactory, TracksAuthor;

    /**
     * created_by / updated_by sengaja tidak fillable: diisi otomatis oleh TracksAuthor.
     *
     * @var list<string>
     */
    protected $fillable = [
        'start_year',
        'end_year',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_year' => 'integer',
            'end_year' => 'integer',
            'status' => ContentStatus::class,
        ];
    }

    /**
     * Pengurus urut per grup (penasihat dulu, lalu bidang) dan sort_order.
     */
    public function members(): HasMany
    {
        return $this->hasMany(ManagementMember::class)
            ->orderByDesc('group') // 'penasihat' > 'bidang'
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function label(): string
    {
        return "{$this->start_year}–{$this->end_year}";
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Published);
    }

    /**
     * Cari berdasarkan tahun awal / akhir periode.
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $year = (int) preg_replace('/\D/', '', (string) $term);

        if ($year <= 0) {
            return;
        }

        $query->where(fn (Builder $q) => $q->where('start_year', $year)->orWhere('end_year', $year));
    }
}
