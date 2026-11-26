<?php

namespace App\Services;

use App\Enums\ContentStatus;
use App\Models\Member;
use App\Models\MemberPointLog;
use App\Models\MemberPointTotal;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Poin papan peringkat (aturan di config/points.php).
 *
 *   award()   : beri poin sekali per (member, action, ref_key) — unique index menolak duplikat. Batas harian
 *               dihitung per member di bawah lock singkat, jadi request bersamaan tidak bisa melewatinya.
 *   revoke()  : tarik poin (konten disembunyikan / dihapus moderator). Baris log tetap ada (revoked_at), jadi
 *               aktivitas yang sama tidak bisa memberi poin baru.
 *   restore() : kembalikan poin yang pernah ditarik (konten ditampilkan lagi). Tidak membuat poin baru.
 *
 * Total per periode ('all' + bulan WIB) diubah atomik di transaksi yang sama dengan log.
 */
class PointService
{
    public const READ_CACHE = 'points:read:';

    /** @return bool true bila poin benar-benar diberikan */
    public function award(Member $member, string $action, string $refKey): bool
    {
        $rule = config("points.actions.{$action}");
        if (! $rule || ! $this->eligible($member)) {
            return false;
        }

        $now = $this->now();

        try {
            return Cache::lock("points:member:{$member->id}", 10)->block(5, function () use ($member, $action, $refKey, $rule, $now) {
                if ($rule['daily_cap'] !== null) {
                    $today = MemberPointLog::query()->where('member_id', $member->id)->where('action', $action)
                        ->where('earned_on', $now->toDateString())->count();
                    if ($today >= $rule['daily_cap']) {
                        return false;
                    }
                }

                try {
                    DB::transaction(function () use ($member, $action, $refKey, $rule, $now) {
                        MemberPointLog::query()->create([
                            'member_id' => $member->id, 'action' => $action, 'points' => $rule['points'],
                            'ref_key' => $refKey, 'earned_on' => $now->toDateString(),
                        ]);
                        $this->addToTotals($member->id, $rule['points'], $now);
                    });
                } catch (UniqueConstraintViolationException) {
                    return false;   // sudah pernah dapat poin untuk aktivitas ini
                }

                return true;
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }

    /** Tarik poin aktivitas ini (semua member yang mendapatkannya; atau satu member). */
    public function revoke(string $action, string $refKey, ?int $memberId = null): void
    {
        $this->toggle($action, [$refKey], $memberId, revoke: true);
    }

    /** @param  list<string>  $refKeys */
    public function revokeMany(string $action, array $refKeys): void
    {
        $this->toggle($action, $refKeys, null, revoke: true);
    }

    public function restore(string $action, string $refKey, ?int $memberId = null): void
    {
        $this->toggle($action, [$refKey], $memberId, revoke: false);
    }

    /**
     * Penulis berita / artikel: 100 poin saat konten dengan penulis member terbit. Penulis diganti, konten
     * kembali draft, atau dihapus → poin penulis lama ditarik; terbit lagi → dikembalikan.
     */
    public function syncAuthor(string $type, Model $content, bool $deleted = false): void
    {
        $refKey = "{$type}:{$content->getKey()}";
        $published = ! $deleted && $content->getAttribute('status') === ContentStatus::Published;
        $memberId = $published ? $content->getAttribute('author_member_id') : null;

        MemberPointLog::query()->where('action', 'author')->where('ref_key', $refKey)->active()
            ->when($memberId, fn ($q) => $q->where('member_id', '!=', $memberId))
            ->pluck('member_id')
            ->each(fn (int $id) => $this->revoke('author', $refKey, $id));

        if (! $memberId) {
            return;
        }

        $this->restore('author', $refKey, $memberId);
        if ($member = Member::query()->find($memberId)) {
            $this->award($member, 'author', $refKey);
        }
    }

    /** Mulai baca artikel / berita: satu sesi aktif per member (membuka artikel lain menggantikannya). */
    public function startReading(Member $member, string $refKey): void
    {
        Cache::put(self::READ_CACHE.$member->id, ['ref' => $refKey, 'at' => now()->getTimestamp()], now()->addHours(2));
    }

    /** Klaim poin baca: hanya bila sesi aktif untuk konten yang sama dan sudah >= read_seconds. */
    public function claimReading(Member $member, string $refKey): bool
    {
        $session = Cache::get(self::READ_CACHE.$member->id);
        if (! is_array($session) || ($session['ref'] ?? null) !== $refKey
            || now()->getTimestamp() - (int) ($session['at'] ?? PHP_INT_MAX) < (int) config('points.read_seconds')) {
            return false;
        }

        Cache::forget(self::READ_CACHE.$member->id);

        return $this->award($member, 'read', $refKey);
    }

    /** Kunjungan harian + poin menu (sekali per menu per hari) dari path halaman website. @return int poin yang didapat */
    public function visit(Member $member, string $path): int
    {
        $today = $this->now()->toDateString();
        $earned = $this->award($member, 'visit', "visit:{$today}") ? (int) config('points.actions.visit.points') : 0;

        $menu = self::menuFor($path);
        if ($menu !== null && $this->award($member, 'menu', "menu:{$menu}:{$today}")) {
            $earned += (int) config('points.actions.menu.points');
        }

        return $earned;
    }

    /** "/kegiatan/galeri/foto" → "galeri" (awalan terpanjang yang cocok); bukan menu → null. */
    public static function menuFor(string $path): ?string
    {
        $path = '/'.trim(strtolower($path), '/');
        $match = null;
        $length = 0;
        foreach ((array) config('points.menus') as $key => $prefix) {
            if (($path === $prefix || str_starts_with($path, $prefix.'/')) && strlen($prefix) > $length) {
                [$match, $length] = [$key, strlen($prefix)];
            }
        }

        return $match;
    }

    public function now(): Carbon
    {
        return now()->setTimezone((string) config('points.timezone'));
    }

    public static function monthPeriod(Carbon $date): string
    {
        return $date->format('Y-m');
    }

    /** Hanya anggota resmi yang belum dihapus (model yang baru dibuat belum memuat nilai default kolom → muat ulang). */
    private function eligible(Member $member): bool
    {
        if ($member->getAttribute('registration_status') === null) {
            $member = $member->fresh() ?? $member;
        }

        return $member->isRegistrationApproved() && ! $member->trashed();
    }

    /** @param  list<string>  $refKeys */
    private function toggle(string $action, array $refKeys, ?int $memberId, bool $revoke): void
    {
        if ($refKeys === []) {
            return;
        }

        DB::transaction(function () use ($action, $refKeys, $memberId, $revoke) {
            $logs = MemberPointLog::query()->where('action', $action)->whereIn('ref_key', $refKeys)
                ->when($memberId, fn ($q) => $q->where('member_id', $memberId))
                ->when($revoke, fn ($q) => $q->whereNull('revoked_at'), fn ($q) => $q->whereNotNull('revoked_at'))
                ->lockForUpdate()->get();

            foreach ($logs as $log) {
                $log->forceFill(['revoked_at' => $revoke ? now() : null])->save();
                $this->addToTotals($log->member_id, $revoke ? -$log->points : $log->points, Carbon::parse($log->earned_on), touch: ! $revoke);
            }
        });
    }

    /** Tambah / kurangi total 'all' + bulan (WIB) secara atomik (upsert: baris baru atau points = points + n). */
    private function addToTotals(int $memberId, int $points, Carbon $on, bool $touch = true): void
    {
        $now = now();
        $rows = collect([MemberPointTotal::ALL, self::monthPeriod($on)])->map(fn (string $period) => [
            'member_id' => $memberId, 'period' => $period, 'points' => max(0, $points),
            'reached_at' => $now, 'updated_at' => $now,
        ])->all();

        $update = ['points' => DB::raw('points + ('.$points.')'), 'updated_at' => $now];
        if ($touch) {
            $update['reached_at'] = $now;
        }

        DB::table('member_point_totals')->upsert($rows, ['member_id', 'period'], $update);
    }
}
