<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class GuidePlaybookIndexRequest extends FormRequest
{
    /** Grid website 3 kolom: 9 = 3 baris */
    public const DEFAULT_PER_PAGE = 9;

    public const MAX_PER_PAGE = 24;

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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
