<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query list video edukasi website: ?search=&category=&page=&per_page=
 */
class GuideVideoIndexRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 4;

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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? static::DEFAULT_PER_PAGE);
    }
}
