<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Rating 1–5 satu member untuk satu diskusi (unik per pasangan; bisa diubah). */
class ForumRating extends Model
{
    protected $fillable = ['discussion_id', 'member_id', 'rating'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }
}
