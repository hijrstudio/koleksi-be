<?php

namespace App\Models;

use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleModel extends Model
{
    use HasFactory, TracksAuthor;

    /** Kolom spesifikasi EV Garage. */
    public const SPEC_COLUMNS = ['battery_kwh', 'ac_kw', 'dc_kw', 'range_km'];

    protected $fillable = [
        'vehicle_brand_id', 'name', 'is_active',
        'battery_kwh', 'ac_kw', 'dc_kw', 'range_km',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'battery_kwh' => 'decimal:1',
            'ac_kw' => 'decimal:1',
            'dc_kw' => 'decimal:1',
            'range_km' => 'integer',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(VehicleBrand::class, 'vehicle_brand_id');
    }

    public function memberVehicles(): HasMany
    {
        return $this->hasMany(MemberVehicle::class);
    }

    public function scopeActive(Builder $q): void
    {
        $q->where('is_active', true);
    }

    /**
     * Tipe aktif dengan spesifikasi lengkap (baterai, AC, jangkauan) = tampil di EV Garage.
     * DC boleh kosong (tidak mendukung DC fast charging).
     */
    public function scopeGarage(Builder $q): void
    {
        $q->active()
            ->whereNotNull('battery_kwh')
            ->whereNotNull('ac_kw')
            ->whereNotNull('range_km');
    }

    public function hasSpecs(): bool
    {
        return $this->battery_kwh !== null && $this->ac_kw !== null && $this->range_km !== null;
    }
}
