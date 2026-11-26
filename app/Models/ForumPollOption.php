<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForumPollOption extends Model
{
    protected $fillable = ['label', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'votes_count' => 'integer'];
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(ForumPoll::class, 'poll_id');
    }
}
