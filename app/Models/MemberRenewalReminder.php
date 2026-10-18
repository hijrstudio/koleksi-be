<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberRenewalReminder extends Model
{
    public const STAGE_EXPIRED = 'expired';

    public $timestamps = false;

    protected $fillable = ['member_id', 'period_end', 'stage', 'channels', 'sent_at'];

    protected function casts(): array
    {
        return [
            'period_end' => 'date',
            'channels' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function stageLabel(): string
    {
        return match ($this->stage) {
            self::STAGE_EXPIRED => 'Keanggotaan berakhir',
            '0' => 'Jatuh tempo hari ini',
            default => "H-{$this->stage} daftar ulang",
        };
    }
}
