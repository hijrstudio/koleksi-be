<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Provinsi (kode 2 digit, mis. "31"). Diisi RegionSeeder.
 */
class Province extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['code', 'name'];

    public function regencies(): HasMany
    {
        return $this->hasMany(Regency::class, 'province_code');
    }
}
