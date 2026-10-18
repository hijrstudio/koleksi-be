<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kecamatan (mis. "31.74.01" Tebet).
 */
class District extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['code', 'regency_code', 'name'];

    public function regency(): BelongsTo
    {
        return $this->belongsTo(Regency::class, 'regency_code');
    }

    public function villages(): HasMany
    {
        return $this->hasMany(Village::class, 'district_code');
    }
}
