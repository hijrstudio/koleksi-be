<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\LocalizesFields;
use App\Models\ManagementMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ManagementMember
 */
class ManagementMemberResource extends JsonResource
{
    use LocalizesFields;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'position' => $this->localized($this->position),
            'photo' => $this->photo_url,
        ];
    }
}
