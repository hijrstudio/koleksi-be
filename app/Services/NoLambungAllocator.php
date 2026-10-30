<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Setting;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * No. lambung (nomor anggota) — unik selamanya, termasuk milik anggota yang sudah dihapus (soft delete).
 *
 * Anggota baru mendapat nomor kosong PERTAMA mulai dari "nomor awal" yang diatur pengurus di CMS
 * (Setting::NO_LAMBUNG_START): mis. awal 309, nomor 309–311 sudah dipakai → 312. Nomor di bawah nomor awal
 * (milik eks-anggota hasil import) tidak dipakai ulang. Kalau nomor awal belum diatur: nomor tertinggi + 1.
 *
 * Aman dari balapan: kunci global + kolom no_lambung UNIQUE di database; kalau tetap bentrok, dicoba nomor berikutnya.
 */
class NoLambungAllocator
{
    private const LOCK = 'members:assign-no-lambung';

    private const MAX_ATTEMPTS = 5;

    /** Nomor awal pencarian (setting pengurus), atau nomor tertinggi + 1 kalau belum diatur. */
    public function start(): int
    {
        $setting = (int) Setting::get(Setting::NO_LAMBUNG_START);

        return $setting > 0 ? $setting : $this->highest() + 1;
    }

    public function highest(): int
    {
        return (int) DB::table('members')->max('no_lambung');
    }

    /**
     * Nomor kosong pertama >= start. Satu query (bukan loop per nomor): kandidat = start, atau (nomor terpakai + 1)
     * untuk setiap nomor >= start; ambil kandidat terkecil yang belum dipakai siapa pun (termasuk soft delete).
     */
    public function next(): int
    {
        $start = $this->start();

        $row = DB::selectOne(
            'SELECT MIN(c.n) AS n FROM (
                SELECT ? AS n
                UNION ALL
                SELECT no_lambung + 1 FROM members WHERE no_lambung >= ?
            ) c WHERE NOT EXISTS (SELECT 1 FROM members m WHERE m.no_lambung = c.n)',
            [$start, $start],
        );

        return (int) $row->n;
    }

    public function isTaken(int $number, ?Member $except = null): bool
    {
        return DB::table('members')
            ->where('no_lambung', $number)
            ->when($except, fn ($q) => $q->where('id', '!=', $except->id))
            ->exists();
    }

    /**
     * Beri nomor ke anggota baru. $save dipanggil dengan nomor kandidat dan harus menyimpannya ke member
     * (bagian dari transaksi pemanggil); kalau bentrok UNIQUE, dicoba nomor berikutnya.
     *
     * @param  Closure(int): void  $save
     */
    public function assign(Closure $save): int
    {
        return $this->locked(function () use ($save) {
            for ($attempt = 1; ; $attempt++) {
                $number = $this->next();

                try {
                    $save($number);

                    return $number;
                } catch (UniqueConstraintViolationException $e) {
                    if ($attempt >= self::MAX_ATTEMPTS) {
                        throw $e;
                    }
                }
            }
        });
    }

    /**
     * Ganti No. lambung anggota (CMS). Ditolak kalau nomor sudah dipakai anggota lain (termasuk yang dihapus).
     *
     * @param  Closure(): void  $save
     *
     * @throws ValidationException
     */
    public function change(Member $member, int $number, Closure $save): void
    {
        $this->locked(function () use ($member, $number, $save) {
            if ($this->isTaken($number, $member)) {
                throw self::takenException($number);
            }

            try {
                $save();
            } catch (UniqueConstraintViolationException) {
                throw self::takenException($number);
            }
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws ValidationException
     */
    private function locked(Closure $callback): mixed
    {
        try {
            return Cache::lock(self::LOCK, 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['no_lambung' => 'Sedang ada pemberian No. lambung lain. Coba lagi.']);
        }
    }

    private static function takenException(int $number): ValidationException
    {
        return ValidationException::withMessages([
            'no_lambung' => 'No. lambung #'.str_pad((string) $number, 3, '0', STR_PAD_LEFT).' sudah dipakai anggota lain.',
        ]);
    }
}
