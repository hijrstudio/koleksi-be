<?php

namespace App\Http\Requests\Cms;

use App\Models\GuideArticle;
use Illuminate\Validation\Rule;

class GuideArticleRequest extends AuthoredContentRequest
{
    protected function modelClass(): string
    {
        return GuideArticle::class;
    }

    protected function routeParameter(): string
    {
        return 'guide_article';
    }

    protected function extraRules(): array
    {
        return [
            'guide_category_id' => ['required', 'integer', Rule::exists('guide_categories', 'id')],
        ];
    }

    protected function extraData(): array
    {
        return [
            'guide_category_id' => (int) $this->validated('guide_category_id'),
        ];
    }

    public function attributes(): array
    {
        return [...parent::attributes(), 'guide_category_id' => 'kategori'];
    }
}
