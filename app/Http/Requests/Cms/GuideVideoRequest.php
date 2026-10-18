<?php

namespace App\Http\Requests\Cms;

use App\Enums\ContentStatus;
use App\Models\GuideVideo;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuideVideoRequest extends FormRequest
{
    public const MAX_TITLE_LENGTH = 255;

    public const MAX_THUMBNAIL_KB = 2 * 1024;

    /** SVG sengaja tidak diizinkan (bisa berisi script / XSS) */
    public const THUMBNAIL_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const THUMBNAIL_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function authorize(): bool
    {
        $video = $this->route('guide_video');

        return $video instanceof GuideVideo
            ? $this->user()->can('update', $video)
            : $this->user()->can('create', GuideVideo::class);
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
            // Boleh URL YouTube lengkap atau ID video; yang disimpan hanya ID-nya
            'youtube_url' => [
                'required', 'string', 'max:500',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (GuideVideo::parseYoutubeId($value) === null) {
                        $fail('Link YouTube tidak valid. Gunakan link video YouTube, mis. https://www.youtube.com/watch?v=xxxxxxxxxxx atau https://youtu.be/xxxxxxxxxxx.');
                    }
                },
            ],
            'thumbnail' => [
                'nullable', 'file',
                'mimetypes:'.implode(',', self::THUMBNAIL_MIMES),
                'extensions:'.implode(',', self::THUMBNAIL_EXTENSIONS),
                'max:'.self::MAX_THUMBNAIL_KB,
            ],
            'remove_thumbnail' => ['nullable', 'boolean'],
            'status' => ['required', Rule::enum(ContentStatus::class)],
        ];
    }

    /**
     * Data video siap simpan (tanpa thumbnail).
     *
     * @return array<string, mixed>
     */
    public function videoData(): array
    {
        return [
            'guide_category_id' => (int) $this->validated('guide_category_id'),
            'title' => [
                'id' => trim($this->validated('title.id')),
                'en' => filled($this->validated('title.en')) ? trim($this->validated('title.en')) : null,
            ],
            'youtube_id' => GuideVideo::parseYoutubeId($this->validated('youtube_url')),
            'status' => $this->validated('status'),
        ];
    }

    public function attributes(): array
    {
        return [
            'guide_category_id' => 'kategori',
            'title.id' => 'judul (ID)',
            'title.en' => 'judul (EN)',
            'youtube_url' => 'link YouTube',
            'thumbnail' => 'thumbnail',
            'status' => 'status',
        ];
    }
}
