<?php

namespace App\Http\Requests\Cms;

use App\Enums\ContentStatus;
use App\Models\GuideInfographic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuideInfographicRequest extends FormRequest
{
    public const MAX_TITLE_LENGTH = 255;

    /** Infografis biasanya gambar panjang & detail, jadi batasnya lebih besar dari gambar biasa */
    public const MAX_IMAGE_KB = 10 * 1024;

    public const MAX_IMAGE_DIMENSION = 10_000;

    /** SVG sengaja tidak diizinkan (bisa berisi script / XSS) */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function authorize(): bool
    {
        $infographic = $this->infographic();

        return $infographic
            ? $this->user()->can('update', $infographic)
            : $this->user()->can('create', GuideInfographic::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'guide_category_id' => ['required', 'integer', Rule::exists('guide_categories', 'id')],
            // Bahasa Indonesia wajib; EN boleh kosong (fallback ke ID di website)
            'title' => ['required', 'array:id,en'],
            'title.id' => ['required', 'string', 'max:'.self::MAX_TITLE_LENGTH],
            'title.en' => ['nullable', 'string', 'max:'.self::MAX_TITLE_LENGTH],
            // Wajib saat create; saat edit kosong = gambar lama dipertahankan
            'image' => [
                $this->infographic() ? 'nullable' : 'required',
                'file',
                'mimetypes:'.implode(',', self::IMAGE_MIMES),
                'extensions:'.implode(',', self::IMAGE_EXTENSIONS),
                'max:'.self::MAX_IMAGE_KB,
                'dimensions:max_width='.self::MAX_IMAGE_DIMENSION.',max_height='.self::MAX_IMAGE_DIMENSION,
            ],
            'status' => ['required', Rule::enum(ContentStatus::class)],
        ];
    }

    /**
     * Data infografis siap simpan (tanpa gambar).
     *
     * @return array<string, mixed>
     */
    public function infographicData(): array
    {
        return [
            'guide_category_id' => (int) $this->validated('guide_category_id'),
            'title' => [
                'id' => trim($this->validated('title.id')),
                'en' => filled($this->validated('title.en')) ? trim($this->validated('title.en')) : null,
            ],
            'status' => $this->validated('status'),
        ];
    }

    public function attributes(): array
    {
        return [
            'guide_category_id' => 'kategori',
            'title.id' => 'judul (ID)',
            'title.en' => 'judul (EN)',
            'image' => 'gambar infografis',
            'status' => 'status',
        ];
    }

    private function infographic(): ?GuideInfographic
    {
        $infographic = $this->route('guide_infographic');

        return $infographic instanceof GuideInfographic ? $infographic : null;
    }
}
