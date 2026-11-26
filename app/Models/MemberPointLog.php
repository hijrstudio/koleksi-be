<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu poin yang didapat member. Ditulis hanya oleh PointService. */
class MemberPointLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['member_id', 'action', 'points', 'ref_key', 'earned_on'];

    protected function casts(): array
    {
        // earned_on disimpan apa adanya ('Y-m-d', tanggal WIB) supaya perbandingan batas harian sama di MySQL & SQLite
        return ['points' => 'integer', 'revoked_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function scopeActive(Builder $q): void
    {
        $q->whereNull('revoked_at');
    }
}
