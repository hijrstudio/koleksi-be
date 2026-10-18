<?php

namespace App\Http\Requests\Api;

use App\Enums\EventPhase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query list kegiatan website:
 * ?phase=upcoming|ongoing|done&search=&category=&page=&per_page=
 * Tanpa phase = semua kegiatan.
 */
class EventIndexRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 8;

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
            'phase' => ['nullable', Rule::enum(EventPhase::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function phase(): ?EventPhase
    {
        return EventPhase::tryFrom((string) $this->validated('phase'));
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
