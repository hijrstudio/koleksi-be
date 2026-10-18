<?php

namespace App\Http\Requests\Api;

use App\Enums\NewsCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query list berita website:
 * ?search=&category=news_release|ev_news|coverage&sort=latest|oldest&page=&per_page=
 */
class NewsIndexRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 5;

    public const MAX_PER_PAGE = 20;

    public function authorize(): bool
    {
        return true; // sudah dijaga middleware frontend.token
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::enum(NewsCategory::class)],
            'sort' => ['nullable', Rule::in(['latest', 'oldest'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function category(): ?NewsCategory
    {
        return NewsCategory::tryFrom((string) $this->validated('category'));
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }

    public function sortDirection(): string
    {
        return $this->validated('sort') === 'oldest' ? 'asc' : 'desc';
    }
}
