<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberDue;
use App\Models\User;
use App\Support\MembershipTerm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Keanggotaan berlaku per member, 1 tahun dihitung dari tanggal bergabung:
 *
 *   bergabung 10 Feb 2026 → periode 2026-2027 = 10 Feb 2026 – 9 Feb 2027
 *   paid_until   = akhir periode lunas terakhir      (9 Feb 2027)
 *   jatuh tempo  = paid_until + 1 hari               (10 Feb 2027, daftar ulang periode 2027-2028)
 *   active_until = paid_until + masa tenggang        (11 Mar 2027 kalau grace 30 hari)
 *
 * Fase 2 (daftar ulang + payment gateway) menambah: recordManualPayment(), pembayaran online.
 */
class MembershipService
{
    /** Periode keanggotaan member yang mencakup tanggal tsb (default hari ini). */
    public function currentTerm(Member $member, ?Carbon $date = null): MembershipTerm
    {
        return MembershipTerm::containing($member->membershipAnchor(), $date ?? today());
    }

    /** Periode member yang dimulai di tahun tsb ("2026-2027" → 2026). */
    public function termForYear(Member $member, int $year): MembershipTerm
    {
        return MembershipTerm::forYear($member->membershipAnchor(), $year);
    }

    /**
     * Periode yang harus dibayar berikutnya: periode setelah paid_until,
     * atau periode berjalan kalau member belum pernah lunas.
     */
    public function renewalTerm(Member $member, ?Carbon $date = null): MembershipTerm
    {
        return $member->paid_until
            ? MembershipTerm::containing($member->membershipAnchor(), $member->paid_until->copy()->addDay())
            : $this->currentTerm($member, $date);
    }

    /** Ambil tagihan member untuk periode tsb, buat baru kalau belum ada. */
    public function invoiceFor(Member $member, MembershipTerm $term): MemberDue
    {
        // whereDate (bukan firstOrCreate) supaya cocok di semua driver: kolom date bisa tersimpan "Y-m-d H:i:s"
        return $member->dues()->whereDate('period_start', $term->start)->first()
            ?? $member->dues()->create([
                'invoice_number' => $this->invoiceNumber($member, $term),
                'period_label' => $term->label(),
                'period_start' => $term->start,
                'period_end' => $term->end,
                'amount' => config('membership.annual_fee'),
                'status' => MemberDue::UNPAID,
            ]);
    }

    /** Contoh: IUR-2627-0007 (periode 2026-2027, lambung #7). */
    public function invoiceNumber(Member $member, MembershipTerm $term): string
    {
        return sprintf('IUR-%02d%02d-%04d', $term->start->year % 100, ($term->start->year + 1) % 100, $member->no_lambung);
    }

    /** Tandai lunas (dipakai import kalau kolom iuran di Excel terisi). */
    public function markPaid(MemberDue $due, string $method, ?Carbon $paidAt = null, ?User $admin = null, ?string $notes = null): MemberDue
    {
        if (! $due->isSettled()) {
            $due->update([
                'status' => MemberDue::PAID,
                'payment_method' => $method,
                'paid_at' => $paidAt ?? now(),
                'recorded_by' => $admin?->id,
                'notes' => $notes,
            ]);
        }

        return $due;
    }

    /** Bebaskan iuran (mis. member lama saat migrasi data). */
    public function waive(Member $member, MembershipTerm $term, ?User $admin = null, ?string $notes = null): MemberDue
    {
        return DB::transaction(function () use ($member, $term, $admin, $notes) {
            $due = $this->invoiceFor($member, $term);

            if (! $due->isSettled()) {
                $due->update([
                    'status' => MemberDue::WAIVED,
                    'payment_method' => 'waived',
                    'amount' => 0,
                    'recorded_by' => $admin?->id,
                    'paid_at' => now(),
                    'notes' => $notes,
                ]);
            }

            $this->refreshStatus($member);

            return $due;
        });
    }

    /** Tahun pemutihan: periode yang dimulai sebelum tahun ini tidak ditagih (null = semua tunggakan ditagih). */
    public function arrearsCutoffYear(): ?int
    {
        return config('membership.arrears_cutoff_year');
    }

    /**
     * Pemutihan: setiap periode member yang dimulai sebelum $beforeYear dan belum lunas — termasuk periode yang
     * belum punya tagihan (sel kosong di Excel) — dicatat "dibebaskan (pemutihan)", lalu status dihitung ulang
     * sekali. Periode yang sudah lunas/dibebaskan tidak disentuh, jadi aman dijalankan berulang.
     *
     * @return int jumlah periode yang diputihkan
     */
    public function waiveArrears(Member $member, int $beforeYear, ?User $admin = null): int
    {
        $anchor = $member->membershipAnchor();
        if ($anchor->year >= $beforeYear) {
            return 0;
        }

        $label = $beforeYear.'-'.($beforeYear + 1);

        return DB::transaction(function () use ($member, $anchor, $beforeYear, $admin, $label) {
            $count = 0;

            for ($term = $this->termForYear($member, $anchor->year); $term->start->year < $beforeYear; $term = $term->next($anchor)) {
                $due = $this->invoiceFor($member, $term);
                if ($due->isSettled()) {
                    continue;
                }

                $due->update([
                    'status' => MemberDue::WAIVED,
                    'payment_method' => MemberDue::METHOD_AMNESTY,
                    'amount' => 0,
                    'recorded_by' => $admin?->id,
                    'paid_at' => now(),
                    'notes' => "Pemutihan tunggakan sebelum periode {$label}",
                ]);
                $count++;
            }

            if ($count > 0) {
                $this->refreshStatus($member, null, $admin, "Pemutihan tunggakan sebelum periode {$label}");
            }

            return $count;
        });
    }

    /** Akhir periode lunas terakhir. */
    public function computePaidUntil(Member $member): ?Carbon
    {
        $end = $member->dues()->settled()->max('period_end');

        return $end ? Carbon::parse($end) : null;
    }

    /**
     * Hitung ulang paid_until, active_until & status satu member dari iuran.
     * Status hanya diubah kalau status_mode = auto.
     */
    public function refreshStatus(Member $member, ?Carbon $date = null, ?User $admin = null, ?string $reason = null): Member
    {
        $date ??= today();
        $paidUntil = $this->computePaidUntil($member);
        $activeUntil = $paidUntil?->copy()->addDays(config('membership.grace_days'));

        $member->paid_until = $paidUntil;
        $member->active_until = $activeUntil;

        if (! $member->isManualStatus()) {
            $status = $activeUntil && $activeUntil->gte($date)
                ? Member::STATUS_ACTIVE
                : Member::STATUS_INACTIVE;

            $this->applyStatus($member, $status, Member::MODE_AUTO, $admin, $reason ?? $this->autoReason($status, $activeUntil));
        }

        $member->save();

        return $member;
    }

    /**
     * Admin CMS menetapkan status secara manual. Status ini tidak ditimpa perhitungan iuran
     * sampai admin mengembalikannya ke mode otomatis (useAutoStatus).
     */
    public function setManualStatus(Member $member, string $status, User $admin, ?string $reason = null): Member
    {
        return DB::transaction(function () use ($member, $status, $admin, $reason) {
            $this->applyStatus($member, $status, Member::MODE_MANUAL, $admin, $reason, force: true);
            $member->save();

            return $member;
        });
    }

    /** Kembalikan status ke mode otomatis (mengikuti iuran). */
    public function useAutoStatus(Member $member, User $admin, ?string $reason = null): Member
    {
        return DB::transaction(function () use ($member, $admin, $reason) {
            // Mode berubah manual → auto, jadi applyStatus pasti mencatat log
            $member->status_mode = Member::MODE_AUTO;

            return $this->refreshStatus($member, null, $admin, $reason ?: 'Dikembalikan ke mode otomatis (ikuti iuran)');
        });
    }

    private function autoReason(string $status, ?Carbon $activeUntil): string
    {
        if ($status === Member::STATUS_ACTIVE) {
            return 'Iuran lunas, aktif s/d '.$activeUntil->translatedFormat('d M Y');
        }

        return $activeUntil
            ? 'Belum daftar ulang, masa tenggang berakhir '.$activeUntil->translatedFormat('d M Y')
            : 'Belum ada iuran yang lunas';
    }

    /**
     * Ubah status + catat ke member_status_logs bila ada perubahan (atau dipaksa).
     * Tidak menyimpan member; pemanggil yang memanggil save().
     */
    private function applyStatus(Member $member, string $status, string $mode, ?User $admin, ?string $reason, bool $force = false): void
    {
        $from = $member->getOriginal('status') ?? ($member->exists ? $member->status : null);
        $changed = $from !== $status || $member->getOriginal('status_mode') !== $mode;

        $member->status = $status;
        $member->status_mode = $mode;

        if (! $changed && ! $force) {
            return;
        }

        if (! $member->exists) {
            $member->save();
        }

        $member->statusLogs()->create([
            'from_status' => $member->wasRecentlyCreated ? null : $from,
            'to_status' => $status,
            'mode' => $mode,
            'reason' => $reason,
            'changed_by' => $admin?->id,
        ]);
    }
}
