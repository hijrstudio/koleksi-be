<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Total poin member per periode ('all' / 'YYYY-MM'). Ditulis hanya oleh PointService. */
class MemberPointTotal extends Model
{
    public const ALL = 'all';

    public const CREATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['member_id', 'period', 'points', 'reached_at'];

    protected function casts(): array
    {
        return ['points' => 'integer', 'reached_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
