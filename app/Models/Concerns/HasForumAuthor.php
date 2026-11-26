<?php

namespace App\Models\Concerns;

use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Penulis konten forum: member (website) ATAU admin CMS (users). Admin tampil sebagai "Moderator".
 * Member yang sudah dihapus / admin yang dihapus → "Anggota" tanpa nama (kolom penulis di-null-kan).
 */
trait HasForumAuthor
{
    public const ROLE_MEMBER = 'Member';

    public const ROLE_MODERATOR = 'Moderator';

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isByAdmin(): bool
    {
        return $this->user_id !== null;
    }

    public function isAuthoredBy(Member $member): bool
    {
        return $this->member_id !== null && $this->member_id === $member->id;
    }

    /** @return array{name: string, avatar: string, role: string} */
    public function authorPayload(): array
    {
        if ($this->isByAdmin()) {
            return [
                'name' => $this->user?->name ?? 'Admin KOLEKSI',
                'avatar' => Storage::disk(Member::PHOTO_DISK)->url(Member::DEFAULT_PHOTO),
                'role' => self::ROLE_MODERATOR,
            ];
        }

        $member = $this->member;

        return [
            'name' => $member && ! $member->trashed() ? ($member->nama_panggilan ?: $member->nama_lengkap) : 'Anggota',
            'avatar' => $member && ! $member->trashed() ? $member->photo_url : Storage::disk(Member::PHOTO_DISK)->url(Member::DEFAULT_PHOTO),
            'role' => self::ROLE_MEMBER,
        ];
    }
}
