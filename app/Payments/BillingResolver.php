<?php

namespace App\Payments;

use App\Models\Member;
use App\Models\MemberDue;
use App\Services\MembershipService;
use App\Support\MembershipTerm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * SATU-SATUNYA sumber "apa yang harus dibayar member sekarang".
 * Dipakai kartu iuran di dasbor (summary → /member/me `billing`) DAN checkout QRIS (payableDues),
 * jadi total di kartu == nominal QRIS.
 *
 * Aturan (mengikuti kartu iuran yang sudah berjalan):
 *  - tagihan hanya muncul kalau jatuh tempo ≤ jendela pengingat (membership.reminder_days, default 30 hari),
 *    sedang masa tenggang, sudah tidak aktif, atau belum pernah bayar;
 *  - yang ditagih: semua tagihan belum lunas s/d periode berjalan (TUNGGAKAN IKUT DITAGIH)
 *    + periode daftar ulang berikutnya;
 *  - status aktif yang ditetapkan manual oleh pengurus → tidak ada tagihan.
 *
 * Nominal tagihan yang dibuat sebelum iuran tahunan diatur (amount = 0) memakai iuran tahunan saat ini.
 */
class BillingResolver
{
    public function __construct(private MembershipService $membership) {}

    /**
     *   ok       → lunas & jatuh tempo masih > jendela (atau aktif manual)
     *   due_soon → jatuh tempo dalam ≤ jendela hari          (days_left = hari menuju jatuh tempo)
     *   grace    → lewat jatuh tempo, masih masa tenggang     (days_left = hari menuju nonaktif)
     *   expired  → masa tenggang habis, tidak aktif           (days_left = negatif, hari sejak nonaktif)
     *   unpaid   → belum pernah ada iuran yang lunas
     *
     * Tidak mengubah DB (aman dipanggil di setiap GET /member/me).
     *
     * @return array<string, mixed>
     */
    public function summary(Member $member, ?Carbon $date = null): array
    {
        $date = ($date ?? today())->copy()->startOfDay();
        [$state, $daysLeft] = $this->state($member, $date);

        $items = $state === 'ok' ? [] : $this->lines($member, $date)
            ->map(fn (array $l) => [
                'invoice_number' => $l['due']?->invoice_number,
                'period_label' => $l['term']->label(),
                'amount' => $l['amount'],
            ])
            ->all();

        return [
            'state' => $state,
            'days_left' => $daysLeft,
            'window_days' => $this->window(),
            'renewal_date' => $member->paid_until?->copy()->addDay()->toDateString(),
            'grace_until' => $member->active_until?->toDateString(),
            'items' => $items,
            'total' => array_sum(array_column($items, 'amount')),
        ];
    }

    /**
     * Tagihan yang dibayar saat checkout — invoice yang belum ada dibuat, nominal 0 disesuaikan ke iuran tahunan.
     *
     * @return Collection<int, MemberDue> belum lunas, urut periode
     */
    public function payableDues(Member $member, ?Carbon $date = null): Collection
    {
        $date = ($date ?? today())->copy()->startOfDay();

        if ($this->state($member, $date)[0] === 'ok') {
            return collect();
        }

        return $this->lines($member, $date)
            ->map(function (array $l) use ($member) {
                $due = $l['due'] ?? $this->membership->invoiceFor($member, $l['term']);
                if ((int) $due->amount !== $l['amount'] && ! $due->isSettled()) {
                    $due->update(['amount' => $l['amount']]);
                }

                return $due;
            })
            ->reject(fn (MemberDue $d) => $d->isSettled())
            ->values();
    }

    /** @return array{0: string, 1: int|null} */
    private function state(Member $member, Carbon $date): array
    {
        $paidUntil = $member->paid_until?->copy()->startOfDay();
        $activeUntil = $member->active_until?->copy()->startOfDay();

        return match (true) {
            $member->isManualStatus() && $member->isActive() => ['ok', null],
            $paidUntil === null => ['unpaid', null],
            $date->lte($paidUntil) => ($days = (int) $date->diffInDays($paidUntil->copy()->addDay())) <= $this->window()
                ? ['due_soon', $days]
                : ['ok', $days],
            $activeUntil !== null && $date->lte($activeUntil) => ['grace', (int) $date->diffInDays($activeUntil)],
            default => ['expired', -(int) ($activeUntil ?? $paidUntil)->diffInDays($date)],
        };
    }

    /**
     * Yang ditagih:
     *  1. tunggakan lama yang belum lunas sebelum periode daftar ulang (celah di riwayat iuran);
     *  2. SETIAP periode dari periode daftar ulang s/d periode berjalan — invoice-nya sudah ada atau belum —
     *     supaya setelah dibayar paid_until benar-benar menutup hari ini dan member aktif kembali;
     *     kalau jatuh tempo masih di depan (due_soon), cukup periode daftar ulang itu sendiri.
     *
     * @return Collection<int, array{due: MemberDue|null, term: MembershipTerm, amount: int}>
     */
    private function lines(Member $member, Carbon $date): Collection
    {
        $fee = (int) config('membership.annual_fee');
        $anchor = $member->membershipAnchor();
        $renewal = $this->membership->renewalTerm($member, $date);
        $last = MembershipTerm::containing($anchor, $date);
        if ($last->start->lt($renewal->start)) {
            $last = $renewal;
        }

        $amount = fn (?MemberDue $d) => $d && (int) $d->amount > 0 ? (int) $d->amount : $fee;

        $dues = $member->dues()
            ->whereDate('period_start', '<=', $last->start)
            ->orderBy('period_start')
            ->get()
            ->keyBy(fn (MemberDue $d) => $d->period_start->toDateString());

        // 1. Tunggakan lama sebelum periode daftar ulang
        $lines = $dues
            ->filter(fn (MemberDue $d) => $d->status === MemberDue::UNPAID && $d->period_start->lt($renewal->start))
            ->map(fn (MemberDue $d) => [
                'due' => $d,
                'term' => MembershipTerm::forYear($anchor, $d->period_start->year),
                'amount' => $amount($d),
            ])
            ->values();

        // 2. Periode daftar ulang s/d periode berjalan
        for ($term = $renewal; $term->start->lte($last->start); $term = $term->next($anchor)) {
            $due = $dues->get($term->start->toDateString());
            if ($due?->isSettled()) {
                continue;
            }

            $lines->push(['due' => $due, 'term' => $term, 'amount' => $amount($due)]);
        }

        return $lines->values();
    }

    private function window(): int
    {
        return max(config('membership.reminder_days')) ?: 30;
    }
}
