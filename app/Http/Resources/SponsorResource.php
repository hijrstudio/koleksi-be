<?php

namespace App\Http\Resources;

use App\Models\Sponsor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sponsor
 */
class SponsorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->title,
            'logo' => $this->logo_url,
            'url' => $this->url, // website sponsor (opsional), sudah divalidasi http/https di CMS
        ];
    }
}
