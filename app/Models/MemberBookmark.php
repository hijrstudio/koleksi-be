<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Konten yang disimpan member. Tipe = kunci pendek → model + syarat "boleh tampil di website":
 * diskusi yang terbit, berita terbit, artikel yang tampil. Konten yang kemudian disembunyikan / dihapus
 * otomatis tidak muncul di daftar simpanan (baris simpanannya tetap, tidak bocor).
 */
class MemberBookmark extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = ['discussion', 'news', 'article'];

    /** Batas simpanan per member (cegah penyalahgunaan). */
    public const MAX_PER_MEMBER = 500;

    protected $fillable = ['member_id', 'bookmarkable_type', 'bookmarkable_id'];

    /** Query konten yang boleh disimpan / ditampilkan untuk tipe tsb. */
    public static function contentQuery(string $type): Builder
    {
        return match ($type) {
            'discussion' => ForumDiscussion::query()->published(),
            'news' => News::query()->published(),
            'article' => GuideArticle::query()->visible(),
        };
    }
}
