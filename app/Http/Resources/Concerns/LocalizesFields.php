<?php

namespace App\Http\Resources\Concerns;

trait LocalizesFields
{
    /**
     * Kolom json {"id","en"} -> selalu dua key; EN kosong fallback ke ID.
     *
     * @param  array<string, ?string>|null  $value
     * @return array{id: ?string, en: ?string}
     */
    protected function localized(?array $value): array
    {
        $id = filled($value['id'] ?? null) ? $value['id'] : null;
        $en = filled($value['en'] ?? null) ? $value['en'] : $id;

        return ['id' => $id, 'en' => $en];
    }
}
