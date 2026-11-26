<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Setting;
use App\Models\User;

/**
 * Satu-satunya sumber harga keanggotaan (Rupiah):
 *
 *   biaya pendaftaran → iuran periode pertama anggota baru (pendaftar website yang belum membayar, atau member
 *                       yang ditambahkan admin di CMS)
 *   iuran tahunan     → perpanjangan / daftar ulang semua member lain
 *
 * Diatur pengurus di CMS (tabel settings). Selama belum pernah diatur, memakai .env (MEMBERSHIP_ANNUAL_FEE,
 * MEMBERSHIP_REGISTRATION_FEE; kalau yang terakhir kosong = iuran tahunan), jadi tidak ada perubahan harga sampai
 * pengurus menyimpannya.
 *
 * Harga berlaku untuk tagihan yang dibuat SETELAH diubah. Tagihan yang sudah terbit tetap dengan nominalnya,
 * supaya QRIS yang sedang berjalan & riwayat tagihan tidak berubah.
 */
class MembershipFees
{
    public const MIN = 10000;

    public const MAX = 100000000;

    public function annual(): int
    {
        return $this->setting(Setting::ANNUAL_FEE) ?? (int) config('membership.annual_fee');
    }

    public function registration(): int
    {
        return $this->setting(Setting::REGISTRATION_FEE)
            ?? ((int) config('membership.registration_fee') ?: $this->annual());
    }

    /** Iuran periode pertama pendaftar yang belum membayar = biaya pendaftaran; selain itu iuran tahunan. */
    public function forMember(Member $member): int
    {
        return $member->isAwaitingPayment() ? $this->registration() : $this->annual();
    }

    public function update(int $registration, int $annual, User $admin): void
    {
        Setting::put(Setting::REGISTRATION_FEE, (string) $registration, $admin);
        Setting::put(Setting::ANNUAL_FEE, (string) $annual, $admin);
    }

    private function setting(string $key): ?int
    {
        $value = Setting::get($key);

        return $value !== null && $value !== '' ? (int) $value : null;
    }
}
