<?php

namespace App\Http\Requests\Cms;

use App\Enums\ContentStatus;
use App\Models\EventVideo;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Video kegiatan: aturan sama dengan video edukasi (GuideVideoRequest), tanpa kategori; thumbnail maks. 5 MB.
 */
class EventVideoRequest extends FormRequest
{
    public const MAX_THUMBNAIL_KB = 5 * 1024;

    public function authorize(): bool
    {
        $video = $this->route('event_video');

        return $video instanceof EventVideo
            ? $this->user()->can('update', $video)
            : $this->user()->can('create', EventVideo::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Bahasa Indonesia wajib; EN boleh kosong (fallback ke ID di website)
            'title' => ['required', 'array:id,en'],
            'title.id' => ['required', 'string', 'max:'.GuideVideoRequest::MAX_TITLE_LENGTH],
            'title.en' => ['nullable', 'string', 'max:'.GuideVideoRequest::MAX_TITLE_LENGTH],
            // Boleh URL YouTube lengkap atau ID video; yang disimpan hanya ID-nya
            'youtube_url' => [
                'required', 'string', 'max:500',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (EventVideo::parseYoutubeId($value) === null) {
                        $fail('Link YouTube tidak valid. Gunakan link video YouTube, mis. https://www.youtube.com/watch?v=xxxxxxxxxxx atau https://youtu.be/xxxxxxxxxxx.');
                    }
                },
            ],
            'thumbnail' => [
                'nullable', 'file',
                'mimetypes:'.implode(',', GuideVideoRequest::THUMBNAIL_MIMES),
                'extensions:'.implode(',', GuideVideoRequest::THUMBNAIL_EXTENSIONS),
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
            'title' => [
                'id' => trim($this->validated('title.id')),
                'en' => filled($this->validated('title.en')) ? trim($this->validated('title.en')) : null,
            ],
            'youtube_id' => EventVideo::parseYoutubeId($this->validated('youtube_url')),
            'status' => $this->validated('status'),
        ];
    }

    public function attributes(): array
    {
        return [
            'title.id' => 'judul (ID)',
            'title.en' => 'judul (EN)',
            'youtube_url' => 'link YouTube',
            'thumbnail' => 'thumbnail',
            'status' => 'status',
        ];
    }
}
