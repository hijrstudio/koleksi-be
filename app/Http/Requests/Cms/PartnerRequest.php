<?php

namespace App\Http\Requests\Cms;

use App\Enums\ContentStatus;
use App\Models\Partner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PartnerRequest extends FormRequest
{
    public const MAX_TITLE_LENGTH = 150;

    public const MAX_URL_LENGTH = 500;

    public const MAX_SORT = 65_535;

    public const MAX_LOGO_KB = 2 * 1024;

    /**
     * SVG sengaja tidak diizinkan: file SVG bisa berisi script dan akan jalan di domain kita
     * bila URL-nya dibuka langsung (stored XSS). Gunakan PNG / WEBP transparan.
     */
    public const LOGO_MIMES = ['image/png', 'image/webp', 'image/jpeg'];

    public const LOGO_EXTENSIONS = ['png', 'webp', 'jpg', 'jpeg'];

    public function authorize(): bool
    {
        $partner = $this->partner();

        return $partner
            ? $this->user()->can('update', $partner)
            : $this->user()->can('create', Partner::class);
    }

    /**
     * URL tanpa skema (mis. "koleksi.id") dilengkapi https:// agar admin tidak perlu mengetiknya.
     */
    protected function prepareForValidation(): void
    {
        $url = $this->input('url');

        if (is_string($url) && ($url = trim($url)) !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $this->merge(['url' => 'https://'.$url]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.self::MAX_TITLE_LENGTH],
            'logo' => [
                $this->partner() ? 'nullable' : 'required', // edit: kosong = logo lama dipertahankan
                'file',
                'mimetypes:'.implode(',', self::LOGO_MIMES),
                'extensions:'.implode(',', self::LOGO_EXTENSIONS),
                'max:'.self::MAX_LOGO_KB,
            ],
            // Hanya http/https: mencegah link javascript:, data:, dll. di website
            'url' => ['nullable', 'string', 'max:'.self::MAX_URL_LENGTH, 'url:http,https'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_SORT],
            'status' => ['required', Rule::enum(ContentStatus::class)],
        ];
    }

    /**
     * Data siap simpan (tanpa logo). Urutan kosong = taruh di paling akhir.
     *
     * @return array<string, mixed>
     */
    public function partnerData(): array
    {
        return [
            'title' => trim($this->validated('title')),
            'url' => $this->validated('url') ?: null,
            'sort' => $this->validated('sort') ?? $this->partner()?->sort ?? Partner::nextSort(),
            'status' => $this->validated('status'),
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'nama partner',
            'logo' => 'logo',
            'url' => 'URL',
            'sort' => 'urutan',
            'status' => 'status',
        ];
    }

    private function partner(): ?Partner
    {
        $partner = $this->route('partner');

        return $partner instanceof Partner ? $partner : null;
    }
}
