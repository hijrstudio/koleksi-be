<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MemberVehicle extends Model
{
    public const PENDING = 'pending';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    /** Field yang kalau diubah member, status verifikasi kembali ke pending. */
    public const SENSITIVE = ['vin', 'plat_kode', 'plat_nomor', 'plat_seri', 'plat_status'];

    protected $fillable = [
        'member_id', 'vin', 'plat_kode', 'plat_nomor', 'plat_seri', 'plat_status',
        'vehicle_brand_id', 'vehicle_model_id', 'brand_other', 'model_other',
        'tahun', 'warna', 'is_primary',
    ];

    protected $appends = ['plat_display'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
            'tahun' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Normalisasi otomatis: huruf besar, tanpa spasi
        static::saving(function (self $v) {
            $v->vin = strtoupper(preg_replace('/\s+/', '', (string) $v->vin));
            $v->plat_kode = strtoupper(trim((string) $v->plat_kode));
            $v->plat_nomor = trim((string) $v->plat_nomor);
            $v->plat_seri = $v->plat_seri ? strtoupper(trim($v->plat_seri)) : null;
            $v->plat_full = $v->plat_kode.$v->plat_nomor.$v->plat_seri;
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(VehicleBrand::class, 'vehicle_brand_id');
    }

    public function vehicleModel(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MemberVehicleDocument::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** "B 1234 XYZ" */
    public function getPlatDisplayAttribute(): string
    {
        return trim("{$this->plat_kode} {$this->plat_nomor} {$this->plat_seri}");
    }

    /** Nama merek + tipe, termasuk isian "lainnya". */
    public function displayName(): string
    {
        return trim(($this->brand?->name ?? $this->brand_other ?? '').' '.($this->vehicleModel?->name ?? $this->model_other ?? ''));
    }

    public function scopeVerified(Builder $q): void
    {
        $q->where('verification_status', self::VERIFIED);
    }
}
