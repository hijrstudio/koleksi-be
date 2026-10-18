<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query tabel EV Garage website:
 * ?brand={slug}&search=&sort=asc|desc&page=&per_page=
 */
class EvGarageIndexRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 18;

    public const MAX_PER_PAGE = 54;

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
            'brand' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function brand(): ?string
    {
        return $this->validated('brand') ?: null;
    }

    public function search(): string
    {
        return trim((string) $this->validated('search'));
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }

    public function sortDirection(): string
    {
        return $this->validated('sort') === 'desc' ? 'desc' : 'asc';
    }
}
