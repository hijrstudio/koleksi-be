<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberStatusLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['member_id', 'from_status', 'to_status', 'mode', 'reason', 'changed_by'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
