<?php

namespace App\Http\Requests\Cms;

use App\Enums\ContentStatus;
use App\Models\GalleryAlbum;
use App\Models\GalleryPhoto;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class GalleryAlbumRequest extends FormRequest
{
    public const MAX_TITLE_LENGTH = 255;

    public const MAX_PHOTOS = 200;

    public const MAX_COVER_KB = 5 * 1024;

    /** Foto album (upload satu per satu lewat AJAX) */
    public const MAX_PHOTO_KB = 10 * 1024;

    public const MAX_PHOTO_DIMENSION = 10_000;

    /** SVG sengaja tidak diizinkan (bisa berisi script / XSS) */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function authorize(): bool
    {
        $album = $this->album();

        return $album
            ? $this->user()->can('update', $album)
            : $this->user()->can('create', GalleryAlbum::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $album = $this->album();

        return [
            // Bahasa Indonesia wajib; EN boleh kosong (fallback ke ID di website)
            'title' => ['required', 'array:id,en'],
            'title.id' => ['required', 'string', 'max:'.self::MAX_TITLE_LENGTH],
            'title.en' => ['nullable', 'string', 'max:'.self::MAX_TITLE_LENGTH],
            'gallery_date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::enum(ContentStatus::class)],
            // Cover wajib saat create; saat edit kosong = cover lama dipertahankan
            'image' => [
                $album ? 'nullable' : 'required',
                'file',
                'mimetypes:'.implode(',', self::IMAGE_MIMES),
                'extensions:'.implode(',', self::IMAGE_EXTENSIONS),
                'max:'.self::MAX_COVER_KB,
            ],

            // Repeater foto. Urutan di form = urutan tampil.
            'photos' => ['required', 'array', 'min:1', 'max:'.self::MAX_PHOTOS],
            'photos.*' => ['required', 'array:id,path'],
            // Foto lama: hanya id, dan id harus milik album ini
            'photos.*.id' => $album
                ? ['nullable', 'integer', 'distinct', Rule::exists('gallery_photos', 'id')->where('gallery_album_id', $album->id)]
                : ['prohibited'],
            // Foto baru: path hasil endpoint upload, file harus ada, dan belum dipakai foto lain
            'photos.*.path' => [
                'nullable', 'required_without:photos.*.id', 'prohibits:photos.*.id', 'string', 'distinct',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! GalleryPhoto::isUploadPath($value) || ! Storage::disk(GalleryPhoto::DISK)->exists($value)) {
                        $fail('Foto tidak valid atau sudah tidak tersedia. Upload ulang foto tersebut.');
                    }
                },
                Rule::unique('gallery_photos', 'image'),
            ],
        ];
    }

    /**
     * Baris foto sesuai urutan form.
     *
     * @return list<array{id: ?int, path: ?string, sort_order: int}>
     */
    public function photoRows(): array
    {
        $rows = [];

        foreach (array_values($this->validated('photos')) as $index => $photo) {
            $rows[] = [
                'id' => isset($photo['id']) ? (int) $photo['id'] : null,
                'path' => isset($photo['id']) ? null : $photo['path'],
                'sort_order' => $index + 1,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function albumData(): array
    {
        return [
            'title' => [
                'id' => trim($this->validated('title.id')),
                'en' => filled($this->validated('title.en')) ? trim($this->validated('title.en')) : null,
            ],
            'gallery_date' => $this->validated('gallery_date'),
            'status' => $this->validated('status'),
        ];
    }

    public function attributes(): array
    {
        return [
            'title.id' => 'judul (ID)',
            'title.en' => 'judul (EN)',
            'gallery_date' => 'tanggal galeri',
            'status' => 'status',
            'image' => 'cover album',
            'photos' => 'foto galeri',
            'photos.*.path' => 'foto',
            'photos.*.id' => 'foto',
        ];
    }

    public function messages(): array
    {
        return [
            'photos.required' => 'Tambahkan minimal satu foto ke album.',
            'photos.max' => 'Maksimal :max foto per album.',
            'photos.*.path.unique' => 'Foto ini sudah dipakai di album lain. Upload ulang foto tersebut.',
        ];
    }

    private function album(): ?GalleryAlbum
    {
        $album = $this->route('gallery_album');

        return $album instanceof GalleryAlbum ? $album : null;
    }
}
