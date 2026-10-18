<?php

namespace App\Models\Concerns;

use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Penulis konten: member (author_member_id) ATAU admin CMS (author_user_id), tepat satu.
 * Dua kolom terpisah agar keduanya punya foreign key asli ke tabelnya masing-masing.
 *
 * Berbeda dengan created_by / updated_by (TracksAuthor) yang mencatat admin yang menginput.
 */
trait HasAuthor
{
    public const AUTHOR_USER = 'user';

    public const AUTHOR_MEMBER = 'member';

    public static function bootHasAuthor(): void
    {
        static::saving(function ($model) {
            // Keduanya null hanya boleh terjadi karena akun penulis dihapus (nullOnDelete), bukan saat menyimpan.
            if (($model->author_member_id === null) === ($model->author_user_id === null)) {
                throw new LogicException(class_basename($model).' harus punya tepat satu penulis (member atau admin).');
            }
        });
    }

    /** Member yang sudah dihapus (soft delete) tetap tampil sebagai penulis */
    public function authorMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'author_member_id')->withTrashed();
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function authorType(): ?string
    {
        return match (true) {
            $this->author_member_id !== null => self::AUTHOR_MEMBER,
            $this->author_user_id !== null => self::AUTHOR_USER,
            default => null,
        };
    }

    public function authorName(): ?string
    {
        return match ($this->authorType()) {
            self::AUTHOR_MEMBER => $this->authorMember?->nama_lengkap,
            self::AUTHOR_USER => $this->authorUser?->name,
            default => null,
        };
    }

    public function setAuthorMember(int $memberId): static
    {
        $this->author_member_id = $memberId;
        $this->author_user_id = null;

        return $this;
    }

    public function setAuthorUser(int $userId): static
    {
        $this->author_user_id = $userId;
        $this->author_member_id = null;

        return $this;
    }

    /**
     * Filter berdasarkan tipe penulis (user / member); nilai lain diabaikan.
     */
    public function scopeAuthoredBy(Builder $query, ?string $type): void
    {
        match ($type) {
            self::AUTHOR_MEMBER => $query->whereNotNull('author_member_id'),
            self::AUTHOR_USER => $query->whereNotNull('author_user_id'),
            default => null,
        };
    }
}
