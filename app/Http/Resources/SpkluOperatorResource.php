<?php

namespace App\Http\Resources;

use App\Models\SpkluOperator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SpkluOperator
 */
class SpkluOperatorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'brand_spklu' => $this->brand_spklu,
            'nama_operator' => $this->nama_operator,
            'logo' => $this->logo_url,
            'aplikasi' => $this->aplikasi,
            // Sudah divalidasi https + domain resmi store di CMS
            'link_playstore' => $this->link_playstore,
            'link_appstore' => $this->link_appstore,
        ];
    }
}
