<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu suara member di satu polling (unique poll_id + member_id). Ditulis hanya oleh ForumPollService. */
class ForumPollVote extends Model
{
    protected $fillable = ['poll_id', 'option_id', 'member_id'];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(ForumPoll::class, 'poll_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ForumPollOption::class, 'option_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
