<?php

namespace App\Http\Requests\Cms;

use App\Enums\NewsCategory;
use App\Models\News;
use Illuminate\Validation\Rule;

class NewsRequest extends AuthoredContentRequest
{
    protected function modelClass(): string
    {
        return News::class;
    }

    protected function routeParameter(): string
    {
        return 'news';
    }

    protected function extraRules(): array
    {
        return [
            'category' => ['required', Rule::enum(NewsCategory::class)],
        ];
    }

    protected function extraData(): array
    {
        return [
            'category' => $this->validated('category'),
        ];
    }

    public function attributes(): array
    {
        return [...parent::attributes(), 'category' => 'kategori'];
    }
}
