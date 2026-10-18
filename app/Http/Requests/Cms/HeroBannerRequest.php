<?php

namespace App\Http\Requests\Cms;

use App\Enums\ContentStatus;
use App\Models\HeroBanner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class HeroBannerRequest extends FormRequest
{
    /** Ukuran maksimal (KB) */
    public const MAX_IMAGE_KB = 5 * 1024;

    public const MAX_VIDEO_KB = 50 * 1024;

    /** SVG sengaja tidak diizinkan (bisa berisi script / XSS) */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const VIDEO_MIMES = ['video/mp4', 'video/webm'];

    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'webm'];

    public function authorize(): bool
    {
        $banner = $this->route('hero_banner');

        return $banner instanceof HeroBanner
            ? $this->user()->can('update', $banner)
            : $this->user()->can('create', HeroBanner::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isCreate = ! $this->route('hero_banner');

        return [
            'file' => [
                $isCreate ? 'required' : 'nullable',
                'file',
                'mimetypes:'.implode(',', [...self::IMAGE_MIMES, ...self::VIDEO_MIMES]),
                'extensions:'.implode(',', self::EXTENSIONS),
                'max:'.self::MAX_VIDEO_KB,
            ],
            'title' => ['required', 'array:id,en'],
            'title.id' => ['required', 'string', 'max:255'],
            'title.en' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::enum(ContentStatus::class)],
        ];
    }

    /**
     * Gambar punya batas ukuran lebih kecil dari video.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $file = $this->file('file');

                if ($file && in_array($file->getMimeType(), self::IMAGE_MIMES, true)
                    && $file->getSize() > self::MAX_IMAGE_KB * 1024) {
                    $validator->errors()->add('file', 'Ukuran gambar maksimal '.(self::MAX_IMAGE_KB / 1024).' MB.');
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'file' => 'file',
            'title.id' => 'title (ID)',
            'title.en' => 'title (EN)',
            'status' => 'status',
        ];
    }
}
