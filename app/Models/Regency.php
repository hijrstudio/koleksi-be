<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kabupaten / kota (mis. "31.74" Kota Administrasi Jakarta Selatan).
 */
class Regency extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['code', 'province_code', 'name'];

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_code');
    }

    public function districts(): HasMany
    {
        return $this->hasMany(District::class, 'regency_code');
    }
}
