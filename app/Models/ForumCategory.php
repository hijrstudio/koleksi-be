<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kategori diskusi forum (Kendaraan, Infrastruktur, ...), dikelola admin di CMS. */
class ForumCategory extends Model
{
    protected $fillable = ['name', 'slug', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function discussions(): HasMany
    {
        return $this->hasMany(ForumDiscussion::class, 'category_id');
    }

    public function scopeActive(Builder $q): void
    {
        $q->where('is_active', true);
    }

    public function scopeOrdered(Builder $q): void
    {
        $q->orderBy('sort_order')->orderBy('name');
    }
}
