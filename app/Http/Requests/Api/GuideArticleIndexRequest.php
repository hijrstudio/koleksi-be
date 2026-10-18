<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query list artikel website: ?search=&category=&sort=latest|oldest&page=&per_page=
 */
class GuideArticleIndexRequest extends FormRequest
{
    public const SORTS = ['latest', 'oldest'];

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
            'category' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
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
