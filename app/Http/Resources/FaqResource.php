<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\LocalizesFields;
use App\Models\Faq;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Faq
 */
class FaqResource extends JsonResource
{
    use LocalizesFields;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question' => $this->localized($this->question),
            'answer' => $this->localized($this->answer), // HTML tersanitasi
        ];
    }
}
