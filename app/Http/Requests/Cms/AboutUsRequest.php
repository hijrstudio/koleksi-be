<?php

namespace App\Http\Requests\Cms;

use App\Models\AboutUs;
use App\Support\RichTextSanitizer;
use Illuminate\Foundation\Http\FormRequest;

class AboutUsRequest extends FormRequest
{
    /** Panjang maksimal HTML (setelah disanitasi) per bahasa */
    public const MAX_HTML_LENGTH = 50_000;

    public const MAX_VISI_LENGTH = 2_000;

    /** Ukuran maksimal (KB) */
    public const MAX_IMAGE_KB = 5 * 1024;

    public const MAX_DOC_KB = 10 * 1024;

    /** SVG sengaja tidak diizinkan (bisa berisi script / XSS) */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public const DOC_EXTENSIONS = ['pdf', 'doc', 'docx'];

    public const LABELS = [
        'summary' => 'Ringkasan',
        'image' => 'Gambar',
        'profil_organisasi' => 'Profil Organisasi',
        'visi' => 'Visi',
        'misi' => 'Misi',
        'aturan_ad_art' => 'Aturan AD/ART',
        'syarat_anggota' => 'Syarat Anggota',
        'daftar_ulang_anggota' => 'Daftar Ulang Anggota',
        'sponsor_doc' => 'Dokumen Sponsor',
        'mitra_doc' => 'Dokumen Mitra',
    ];

    public function authorize(): bool
    {
        return $this->user()->can('update', AboutUs::class);
    }

    /**
     * HTML disanitasi sebelum validasi, sehingga aturan required/max berlaku pada konten yang benar-benar disimpan.
     */
    protected function prepareForValidation(): void
    {
        $sanitizer = app(RichTextSanitizer::class);
        $merge = [];

        foreach (AboutUs::RICH_TEXT_FIELDS as $field) {
            $value = $this->input($field);

            if (! is_array($value)) {
                continue; // dibiarkan, akan ditolak oleh rule "array"
            }

            foreach (AboutUs::LOCALES as $locale) {
                if (is_string($value[$locale] ?? null)) {
                    $value[$locale] = $sanitizer->clean($value[$locale]);
                }
            }

            $merge[$field] = $value;
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];

        // Bahasa Indonesia wajib untuk ringkasan; EN boleh kosong (fallback ke ID di website)
        foreach (AboutUs::RICH_TEXT_FIELDS as $field) {
            $rules[$field] = [$field === 'summary' ? 'required' : 'nullable', 'array:id,en'];
            $rules["$field.id"] = [$field === 'summary' ? 'required' : 'nullable', 'string', 'max:'.self::MAX_HTML_LENGTH];
            $rules["$field.en"] = ['nullable', 'string', 'max:'.self::MAX_HTML_LENGTH];
        }

        $rules['visi'] = ['nullable', 'array:id,en'];
        $rules['visi.id'] = ['nullable', 'string', 'max:'.self::MAX_VISI_LENGTH];
        $rules['visi.en'] = ['nullable', 'string', 'max:'.self::MAX_VISI_LENGTH];

        $rules['image'] = [
            'nullable', 'file',
            'mimetypes:'.implode(',', self::IMAGE_MIMES),
            'extensions:'.implode(',', self::IMAGE_EXTENSIONS),
            'max:'.self::MAX_IMAGE_KB,
        ];
        $rules['aturan_ad_art'] = ['nullable', 'file', 'mimetypes:application/pdf', 'extensions:pdf', 'max:'.self::MAX_DOC_KB];

        foreach (['sponsor_doc', 'mitra_doc'] as $field) {
            $rules[$field] = [
                'nullable', 'file',
                'mimes:'.implode(',', self::DOC_EXTENSIONS),
                'extensions:'.implode(',', self::DOC_EXTENSIONS),
                'max:'.self::MAX_DOC_KB,
            ];
        }

        foreach (AboutUs::FILE_FIELDS as $field) {
            $rules["remove_$field"] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    /**
     * Data teks siap simpan: field json selalu berbentuk {"id": ?, "en": ?} atau null jika keduanya kosong.
     *
     * @return array<string, array{id: ?string, en: ?string}|null>
     */
    public function textData(): array
    {
        $data = [];

        foreach ([...AboutUs::RICH_TEXT_FIELDS, ...AboutUs::PLAIN_TEXT_FIELDS] as $field) {
            $value = [];

            foreach (AboutUs::LOCALES as $locale) {
                $text = $this->validated("$field.$locale");
                $value[$locale] = filled($text) ? trim($text) : null;
            }

            $data[$field] = array_filter($value) === [] ? null : $value;
        }

        return $data;
    }

    public function attributes(): array
    {
        $attributes = [];

        foreach (self::LABELS as $field => $label) {
            $attributes[$field] = $label;
            $attributes["$field.id"] = "$label (ID)";
            $attributes["$field.en"] = "$label (EN)";
        }

        return $attributes;
    }
}
