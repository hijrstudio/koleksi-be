<?php

namespace App\Models;

use App\Enums\ManagementGroup;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ManagementMember extends Model
{
    use HasFactory;

    public const DISK = 'public';

    public const DIRECTORY = 'management';

    /**
     * management_period_id sengaja tidak fillable: selalu diisi lewat relasi periode.
     *
     * @var list<string>
     */
    protected $fillable = [
        'group',
        'name',
        'position',
        'photo',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'group' => ManagementGroup::class,
            'position' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ManagementPeriod::class, 'management_period_id');
    }

    /**
     * Jabatan sesuai locale, fallback ke bahasa Indonesia.
     */
    public function positionFor(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->position[$locale] ?? $this->position['id'] ?? '';
    }

    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->photo
            ? Storage::disk(self::DISK)->url($this->photo)
            : null);
    }
}
