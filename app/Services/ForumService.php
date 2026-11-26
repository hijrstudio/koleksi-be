<?php

namespace App\Services;

use App\Models\ForumComment;
use App\Models\ForumDiscussion;
use App\Models\ForumRating;
use App\Models\Member;
use App\Models\User;
use App\Notifications\ForumCommentReplied;
use App\Notifications\ForumDiscussionCommented;
use App\Notifications\NewForumDiscussion;
use App\Support\CoverImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Logika forum: buat diskusi & komentar, rating, hitungan dilihat, notifikasi lonceng.
 *
 * Counter di forum_discussions (comments_count, rating_sum/count, views_count) diubah dengan increment atomik
 * di database (bukan baca-tambah-tulis), jadi aman walau banyak member menulis bersamaan. Notifikasi dikirim
 * setelah transaksi tersimpan (afterCommit), hanya ke lonceng (database), tanpa e-mail.
 */
class ForumService
{
    /**
     * @param  array{category_id: int, title: string, body: string}  $data
     */
    public function createDiscussion(array $data, Member|User $author, ?UploadedFile $image = null): ForumDiscussion
    {
        // Gambar diproses sebelum transaksi: gambar rusak → tidak ada data yang sempat tersimpan
        $imagePath = $image ? $this->storeImage($image) : null;

        try {
            $discussion = DB::transaction(function () use ($data, $author, $imagePath) {
                $discussion = new ForumDiscussion([
                    'category_id' => $data['category_id'],
                    'title' => self::cleanLine($data['title']),
                    'body' => self::cleanText($data['body']),
                    'image' => $imagePath,
                    'status' => ForumDiscussion::PUBLISHED,
                ]);
                $author instanceof Member ? $discussion->member_id = $author->id : $discussion->user_id = $author->id;
                $discussion->save();

                return $discussion;
            });
        } catch (Throwable $e) {
            if ($imagePath) {
                Storage::disk(ForumDiscussion::IMAGE_DISK)->delete($imagePath);
            }

            throw $e;
        }

        if ($author instanceof Member) {
            Notification::send(app(MemberRegistrationService::class)->admins(), new NewForumDiscussion($discussion));
        }
        $this->forgetStats();

        return $discussion;
    }

    /** Ganti / hapus gambar sampul (admin CMS). */
    public function replaceImage(ForumDiscussion $discussion, ?UploadedFile $image, bool $remove = false): void
    {
        if (! $image && ! $remove) {
            return;
        }

        $old = $discussion->image;
        $discussion->image = $image ? $this->storeImage($image) : null;
        $discussion->save();

        if ($old) {
            Storage::disk(ForumDiscussion::IMAGE_DISK)->delete($old);
        }
    }

    /**
     * Komentar baru / balasan. Balasan pada tingkat terdalam ditempel ke induk yang sama (depth tetap MAX_DEPTH).
     *
     * @throws ValidationException
     */
    public function comment(ForumDiscussion $discussion, Member|User $author, string $body, ?int $parentId = null): ForumComment
    {
        if ($author instanceof Member && $discussion->is_locked) {
            throw ValidationException::withMessages(['body' => 'Komentar untuk diskusi ini sudah ditutup.']);
        }

        $parent = null;
        if ($parentId !== null) {
            $parent = $discussion->comments()->published()->find($parentId);
            if (! $parent) {
                throw ValidationException::withMessages(['parent_id' => 'Komentar yang dibalas tidak ditemukan.']);
            }
        }

        $comment = DB::transaction(function () use ($discussion, $author, $body, $parent) {
            $comment = new ForumComment(['body' => self::cleanText($body), 'status' => ForumComment::PUBLISHED]);
            $comment->discussion_id = $discussion->id;
            if ($parent) {
                $deepest = $parent->depth >= ForumComment::MAX_DEPTH;
                $comment->parent_id = $deepest ? ($parent->parent_id ?? $parent->id) : $parent->id;
                $comment->depth = $deepest ? ForumComment::MAX_DEPTH : $parent->depth + 1;
            }
            $author instanceof Member ? $comment->member_id = $author->id : $comment->user_id = $author->id;
            $comment->save();

            ForumDiscussion::query()->whereKey($discussion->id)->toBase()->update([
                'comments_count' => DB::raw('comments_count + 1'),
                'last_activity_at' => now(),
            ]);

            return $comment;
        });

        $this->notifyComment($discussion, $comment, $parent, $author);
        $this->forgetStats();

        return $comment;
    }

    /**
     * Rating 1–5 (member, bukan untuk diskusinya sendiri). Rating ulang mengganti rating lama.
     *
     * @return array{average: float, count: int, mine: int}
     *
     * @throws ValidationException
     */
    public function rate(ForumDiscussion $discussion, Member $member, int $rating): array
    {
        if ($discussion->isAuthoredBy($member)) {
            throw ValidationException::withMessages(['rating' => 'Anda tidak bisa memberi rating untuk diskusi sendiri.']);
        }

        DB::transaction(function () use ($discussion, $member, $rating) {
            // Kunci baris diskusi: rating bersamaan dari member yang sama tidak menggandakan hitungan
            ForumDiscussion::query()->whereKey($discussion->id)->lockForUpdate()->first();
            $existing = ForumRating::query()->where('discussion_id', $discussion->id)->where('member_id', $member->id)->first();

            if ($existing) {
                $delta = $rating - $existing->rating;
                $existing->update(['rating' => $rating]);
                ForumDiscussion::query()->whereKey($discussion->id)->toBase()
                    ->update(['rating_sum' => DB::raw('rating_sum + ('.(int) $delta.')')]);
            } else {
                ForumRating::create(['discussion_id' => $discussion->id, 'member_id' => $member->id, 'rating' => $rating]);
                ForumDiscussion::query()->whereKey($discussion->id)->toBase()->update([
                    'rating_sum' => DB::raw('rating_sum + '.(int) $rating),
                    'rating_count' => DB::raw('rating_count + 1'),
                ]);
            }
        });

        $discussion->refresh();

        return ['average' => $discussion->ratingAverage(), 'count' => $discussion->rating_count, 'mine' => $rating];
    }

    /** +1 dilihat, maks sekali per member per diskusi per hari (refresh berulang tidak menambah). */
    public function recordView(ForumDiscussion $discussion, Member $member): void
    {
        $key = "forum:view:{$discussion->id}:{$member->id}:".today()->toDateString();

        if (Cache::add($key, true, today()->addDay())) {
            ForumDiscussion::query()->whereKey($discussion->id)->toBase()->increment('views_count');
            $discussion->views_count++;
        }
    }

    /** Hitung ulang jumlah komentar terbit (setelah admin menyembunyikan / menghapus komentar). */
    public function refreshCommentCount(ForumDiscussion $discussion): void
    {
        $discussion->forceFill(['comments_count' => $discussion->comments()->published()->count()])->saveQuietly();
        $this->forgetStats();
    }

    /**
     * Statistik sidebar & widget forum (di-cache singkat: dipanggil di setiap halaman forum).
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return Cache::remember('forum:stats', now()->addMinutes(5), function () {
            $published = ForumDiscussion::query()->published();

            return [
                'discussions' => (clone $published)->count(),
                'comments' => ForumComment::query()->published()
                    ->whereHas('discussion', fn ($q) => $q->published())->count(),
                // Member yang menulis diskusi / komentar 30 hari terakhir
                'active_members' => DB::query()->fromSub(
                    ForumDiscussion::query()->published()->whereNotNull('member_id')
                        ->where('created_at', '>=', now()->subDays(30))->select('member_id')
                        ->union(ForumComment::query()->published()->whereNotNull('member_id')
                            ->where('created_at', '>=', now()->subDays(30))->select('member_id')),
                    'm',
                )->count(),
            ];
        });
    }

    public function forgetStats(): void
    {
        Cache::forget('forum:stats');
        Cache::forget('forum:widgets');
    }

    /**
     * Widget kanan daftar diskusi: trending (paling ramai dikomentari 14 hari) & anggota teraktif (30 hari).
     *
     * @return array{trending: list<array<string, mixed>>, active_members: list<array<string, mixed>>}
     */
    public function widgets(): array
    {
        return Cache::remember('forum:widgets', now()->addMinutes(10), function () {
            $trending = ForumDiscussion::query()->published()
                ->where('last_activity_at', '>=', now()->subDays(14))
                ->orderByDesc('comments_count')->orderByDesc('last_activity_at')
                ->limit(5)->get(['id', 'slug', 'title', 'created_at'])
                ->map(fn (ForumDiscussion $d) => ['slug' => $d->slug, 'title' => $d->title, 'created_at' => $d->created_at?->toIso8601String()])
                ->all();

            $counts = ForumComment::query()->published()->whereNotNull('member_id')
                ->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('member_id, COUNT(*) AS total')->groupBy('member_id')
                ->orderByDesc('total')->limit(8)->pluck('total', 'member_id');

            $members = Member::query()->registered()->whereIn('id', $counts->keys())->get()->keyBy('id');
            $active = $counts->keys()
                ->map(fn (int $id) => $members->get($id))->filter()
                ->map(fn (Member $m) => ['name' => $m->nama_panggilan ?: $m->nama_lengkap, 'avatar' => $m->photo_url, 'role' => 'Member'])
                ->values()->all();

            return ['trending' => $trending, 'active_members' => $active];
        });
    }

    /** Teks satu baris: spasi dirapikan, tanpa karakter kontrol. */
    public static function cleanLine(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', self::stripControl($text)) ?? '');
    }

    /** Teks bebas: baris dipertahankan, maks 2 baris kosong berturut-turut, tanpa karakter kontrol. */
    public static function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", self::stripControl($text));
        $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text);
    }

    private static function stripControl(string $text): string
    {
        // Karakter kontrol (kecuali baris baru & tab) & karakter arah teks (bisa dipakai menyamarkan isi)
        return preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? '';
    }

    private function storeImage(UploadedFile $image): string
    {
        try {
            $binary = CoverImage::process($image);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['image' => $e->getMessage()]);
        }

        $path = ForumDiscussion::IMAGE_DIR.'/'.now()->format('Y/m').'/'.Str::random(32).'.webp';
        Storage::disk(ForumDiscussion::IMAGE_DISK)->put($path, $binary);

        return $path;
    }

    /** Penulis diskusi: ada komentar baru. Penulis komentar induk: ada balasan. Tidak memberi tahu diri sendiri. */
    private function notifyComment(ForumDiscussion $discussion, ForumComment $comment, ?ForumComment $parent, Member|User $author): void
    {
        $actor = $comment->authorPayload()['name'];
        $notified = [];
        $isSelf = fn (?Member $m) => $m === null || ($author instanceof Member && $m->id === $author->id);

        $parentAuthor = $parent?->member_id ? $parent->member : null;
        if ($parentAuthor && ! $parentAuthor->trashed() && ! $isSelf($parentAuthor)) {
            $parentAuthor->notify(new ForumCommentReplied($discussion, $actor));
            $notified[] = $parentAuthor->id;
        }

        $discussionAuthor = $discussion->member_id ? $discussion->member : null;
        if ($discussionAuthor && ! $discussionAuthor->trashed() && ! $isSelf($discussionAuthor)
            && ! in_array($discussionAuthor->id, $notified, true)) {
            $discussionAuthor->notify(new ForumDiscussionCommented($discussion, $actor));
        }
    }
}
