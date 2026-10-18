<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\LocalizesFields;
use App\Models\AboutUs;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Konten "Tentang Kami". Field rich text berisi HTML yang sudah disanitasi saat disimpan di CMS.
 *
 * @mixin AboutUs
 */
class AboutUsResource extends JsonResource
{
    use LocalizesFields;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'summary' => $this->localized($this->summary),
            'image' => $this->fileUrl('image'),
            'profil_organisasi' => $this->localized($this->profil_organisasi),
            'visi' => $this->localized($this->visi), // teks biasa
            'misi' => $this->localized($this->misi),
            'syarat_anggota' => $this->localized($this->syarat_anggota),
            'daftar_ulang_anggota' => $this->localized($this->daftar_ulang_anggota),
            'documents' => [
                'aturan_ad_art' => $this->fileUrl('aturan_ad_art'),
                'sponsor' => $this->fileUrl('sponsor_doc'),
                'mitra' => $this->fileUrl('mitra_doc'),
            ],
        ];
    }
}
