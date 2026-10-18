<?php

namespace App\Http\Requests\Cms;

use App\Enums\ContentStatus;
use App\Models\GuideCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuideCategoryRequest extends FormRequest
{
    public const MAX_NAME_LENGTH = 150;

    public function authorize(): bool
    {
        $category = $this->route('guide_category');

        return $category instanceof GuideCategory
            ? $this->user()->can('update', $category)
            : $this->user()->can('create', GuideCategory::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Bahasa Indonesia wajib & unik; EN boleh kosong (fallback ke ID di website)
            'name' => ['required', 'array:id,en'],
            'name.id' => [
                'required', 'string', 'max:'.self::MAX_NAME_LENGTH,
                Rule::unique('guide_categories', 'name->id')->ignore($this->route('guide_category')),
            ],
            'name.en' => ['nullable', 'string', 'max:'.self::MAX_NAME_LENGTH],
            'status' => ['required', Rule::enum(ContentStatus::class)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function categoryData(): array
    {
        return [
            'name' => [
                'id' => trim($this->validated('name.id')),
                'en' => filled($this->validated('name.en')) ? trim($this->validated('name.en')) : null,
            ],
            'status' => $this->validated('status'),
        ];
    }

    public function attributes(): array
    {
        return [
            'name.id' => 'nama kategori (ID)',
            'name.en' => 'nama kategori (EN)',
            'status' => 'status',
        ];
    }

    public function messages(): array
    {
        return [
            'name.id.unique' => 'Kategori dengan nama ini sudah ada.',
        ];
    }
}
