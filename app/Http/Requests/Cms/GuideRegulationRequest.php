<?php

namespace App\Http\Requests\Cms;

use App\Enums\ContentStatus;
use App\Models\GuideRegulation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuideRegulationRequest extends FormRequest
{
    public const MAX_TITLE_LENGTH = 255;

    /** PDF dilayani dari server lewat API: batasi ukuran supaya cepat dibuka */
    public const MAX_PDF_KB = 20 * 1024;

    public function authorize(): bool
    {
        $regulation = $this->regulation();

        return $regulation
            ? $this->user()->can('update', $regulation)
            : $this->user()->can('create', GuideRegulation::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.self::MAX_TITLE_LENGTH],
            // Wajib saat create; saat edit kosong = file lama dipertahankan. Isi file dicek (mimetypes), bukan hanya ekstensi
            'file_pdf' => [
                $this->regulation() ? 'nullable' : 'required',
                'file', 'mimetypes:application/pdf', 'extensions:pdf', 'max:'.self::MAX_PDF_KB,
            ],
            'status' => ['required', Rule::enum(ContentStatus::class)],
        ];
    }

    /**
     * Data dokumen siap simpan (tanpa file).
     *
     * @return array<string, mixed>
     */
    public function regulationData(): array
    {
        return [
            'title' => trim($this->validated('title')),
            'status' => $this->validated('status'),
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'judul',
            'file_pdf' => 'file PDF',
            'status' => 'status',
        ];
    }

    private function regulation(): ?GuideRegulation
    {
        $regulation = $this->route('guide_regulation');

        return $regulation instanceof GuideRegulation ? $regulation : null;
    }
}
