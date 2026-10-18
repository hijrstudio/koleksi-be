<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mengisi created_by / updated_by otomatis dari user yang sedang login.
 */
trait TracksAuthor
{
    public static function bootTracksAuthor(): void
    {
        static::creating(function ($model) {
            $model->created_by ??= auth()->id();
            $model->updated_by ??= auth()->id();
        });

        static::updating(function ($model) {
            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
