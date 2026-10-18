<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Desa / kelurahan (mis. "31.74.01.1001" Tebet Timur).
 */
class Village extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['code', 'district_code', 'name'];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_code');
    }
}
