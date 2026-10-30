<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Payments\PaymentService;
use App\Services\MemberRegistrationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Hapus pendaftar yang tidak menyelesaikan pendaftaran, beserta dokumen kendaraannya — supaya data pribadi & file
 * tidak menumpuk dan e-mail / No. HP / kendaraannya bisa dipakai mendaftar lagi:
 *   - belum memverifikasi e-mail dalam N hari (membership.registration.unverified_ttl_days)
 *   - sudah verifikasi tapi tidak membayar iuran pertama sampai batasnya (members.payment_due_at)
 *
 * Sebelum pendaftar belum-bayar dihapus, QR yang masih pending dicek dulu ke gateway: kalau ternyata sudah dibayar,
 * pendaftar diaktifkan, bukan dihapus. Pendaftar yang pembayarannya masih bisa masuk tidak dihapus.
 */
class PruneMemberRegistrations extends Command
{
    protected $signature = 'members:prune-registrations {--dry-run : Tampilkan saja, tidak menghapus apa pun}';

    protected $description = 'Hapus pendaftar member yang tidak memverifikasi e-mail / tidak membayar iuran pertama';

    public function handle(MemberRegistrationService $registrations, PaymentService $payments): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $unverified = 0;
        $unpaid = 0;

        Member::withTrashed()
            ->registration(Member::REG_UNVERIFIED)
            ->where('registered_at', '<', now()->subDays(config('membership.registration.unverified_ttl_days')))
            ->chunkById(100, function ($members) use ($registrations, $dryRun, &$unverified) {
                foreach ($members as $member) {
                    $dryRun
                        ? $this->line("  belum verifikasi: {$member->email} (daftar {$member->registered_at?->toDateTimeString()})")
                        : $registrations->purge($member);
                    $unverified++;
                }
            });

        Member::withTrashed()
            ->registration(Member::REG_AWAITING_PAYMENT)
            ->where('payment_due_at', '<', now())
            ->chunkById(100, function ($members) use ($registrations, $payments, $dryRun, &$unpaid) {
                foreach ($members as $member) {
                    if (! $dryRun) {
                        // QR terakhir mungkin sudah dibayar tapi callback-nya belum masuk
                        foreach ($member->payments()->pending()->get() as $payment) {
                            rescue(fn () => $payments->refresh($payment, force: true), report: true);
                        }
                    }

                    if ($member->refresh()->registration_status !== Member::REG_AWAITING_PAYMENT
                        || $registrations->hasPaymentInFlight($member)) {
                        continue;
                    }

                    $dryRun
                        ? $this->line("  belum bayar: {$member->email} (batas {$member->payment_due_at?->toDateTimeString()})")
                        : $this->purge($registrations, $member);
                    $unpaid++;
                }
            });

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Dihapus: {$unverified} pendaftar belum verifikasi, {$unpaid} pendaftar belum bayar.");

        return self::SUCCESS;
    }

    private function purge(MemberRegistrationService $registrations, Member $member): void
    {
        try {
            $registrations->purge($member);
        } catch (Throwable $e) {
            report($e);
            $this->warn("{$member->email}: {$e->getMessage()}");
        }
    }
}
