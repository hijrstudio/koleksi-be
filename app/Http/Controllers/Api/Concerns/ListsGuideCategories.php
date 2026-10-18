<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\GuideCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Kategori panduan untuk filter website: published dan punya minimal satu konten published
 * pada relasi tertentu (articles / videos / infographics).
 */
trait ListsGuideCategories
{
    protected function guideCategoriesHaving(string $relation): JsonResponse
    {
        $categories = GuideCategory::query()
            ->select(['id', 'name'])
            ->published()
            ->whereHas($relation, fn (Builder $q) => $q->published())
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $categories->map(fn (GuideCategory $category) => [
                'id' => $category->id,
                'name' => [
                    'id' => $category->name['id'] ?? '',
                    'en' => ($category->name['en'] ?? null) ?: ($category->name['id'] ?? ''),
                ],
            ]),
        ]);
    }
}
