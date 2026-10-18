<?php

namespace App\Http\Resources\Concerns;

/**
 * Nama penulis konten (model dengan trait HasAuthor) untuk website.
 * Relasi authorMember & authorUser harus sudah di-eager load oleh pemanggil (hindari N+1).
 */
trait FormatsAuthor
{
    /** "Nama Lengkap (KOLEKSI 104)" untuk member, nama admin untuk user CMS. */
    protected function authorLabel(): ?string
    {
        if ($this->author_member_id !== null) {
            $member = $this->authorMember;

            return $member ? "{$member->nama_lengkap} (KOLEKSI {$member->no_lambung})" : null;
        }

        return $this->authorUser?->name;
    }

    protected function authorLoaded(): bool
    {
        return $this->resource->relationLoaded('authorMember') && $this->resource->relationLoaded('authorUser');
    }
}
