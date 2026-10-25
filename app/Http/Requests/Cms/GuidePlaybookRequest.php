<?php

namespace App\Http\Requests\Cms;

use App\Enums\ContentStatus;
use App\Models\GuidePlaybook;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuidePlaybookRequest extends FormRequest
{
    public const MAX_TITLE_LENGTH = 255;

    public const MAX_COVER_KB = 5 * 1024;

    public const MAX_COVER_DIMENSION = 6_000;

    /** SVG sengaja tidak diizinkan (bisa berisi script / XSS) */
    public const COVER_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const COVER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** PDF dilayani dari server lewat API: batasi ukuran supaya cepat dibuka di popup */
    public const MAX_PDF_KB = 20 * 1024;

    public function authorize(): bool
    {
        $playbook = $this->playbook();

        return $playbook
            ? $this->user()->can('update', $playbook)
            : $this->user()->can('create', GuidePlaybook::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->playbook() ? 'nullable' : 'required';

        return [
            // Bahasa Indonesia wajib; EN boleh kosong (fallback ke ID di website)
            'title' => ['required', 'array:id,en'],
            'title.id' => ['required', 'string', 'max:'.self::MAX_TITLE_LENGTH],
            'title.en' => ['nullable', 'string', 'max:'.self::MAX_TITLE_LENGTH],
            // Wajib saat create; saat edit kosong = file lama dipertahankan
            'cover_image' => [
                $required,
                'file',
                'mimetypes:'.implode(',', self::COVER_MIMES),
                'extensions:'.implode(',', self::COVER_EXTENSIONS),
                'max:'.self::MAX_COVER_KB,
                'dimensions:max_width='.self::MAX_COVER_DIMENSION.',max_height='.self::MAX_COVER_DIMENSION,
            ],
            // Isi file dicek (mimetypes), bukan hanya ekstensi
            'file_pdf' => [$required, 'file', 'mimetypes:application/pdf', 'extensions:pdf', 'max:'.self::MAX_PDF_KB],
            'status' => ['required', Rule::enum(ContentStatus::class)],
        ];
    }

    /**
     * Data playbook siap simpan (tanpa file).
     *
     * @return array<string, mixed>
     */
    public function playbookData(): array
    {
        return [
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
            'title.id' => 'judul (ID)',
            'title.en' => 'judul (EN)',
            'cover_image' => 'cover',
            'file_pdf' => 'file PDF',
            'status' => 'status',
        ];
    }

    private function playbook(): ?GuidePlaybook
    {
        $playbook = $this->route('guide_playbook');

        return $playbook instanceof GuidePlaybook ? $playbook : null;
    }
}
