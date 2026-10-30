<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Notifications\MemberRegistrationPaymentLink;
use App\Services\MemberRegistrationService;
use Illuminate\Console\Command;

/**
 * Sekali jalan saat pindah ke alur pendaftaran tanpa review: pendaftar lama yang masih "pending" (menunggu review
 * pengurus) dipindah ke "awaiting_payment" dan dikirimi link bayar iuran pendaftaran (berlaku sesuai
 * membership.registration.payment_ttl_hours). Pendaftaran yang sudah disetujui / ditolak tidak disentuh.
 */
class ConvertPendingRegistrations extends Command
{
    protected $signature = 'members:convert-pending-registrations {--dry-run : Tampilkan saja, tidak mengubah apa pun}';

    protected $description = 'Pindahkan pendaftar "menunggu review" ke alur bayar iuran pendaftaran & kirim link bayar';

    public function handle(MemberRegistrationService $registrations): int
    {
        $count = 0;

        Member::query()->registration(Member::REG_PENDING)->chunkById(100, function ($members) use ($registrations, &$count) {
            foreach ($members as $member) {
                $count++;
                if ($this->option('dry-run')) {
                    $this->line("  {$member->email} (daftar {$member->registered_at?->toDateTimeString()})");

                    continue;
                }

                $member->forceFill([
                    'registration_status' => Member::REG_AWAITING_PAYMENT,
                    'payment_due_at' => $registrations->paymentDeadline(),
                ])->save();
                $member->notify(new MemberRegistrationPaymentLink);
            }
        });

        $this->info(($this->option('dry-run') ? '[DRY RUN] ' : '')."{$count} pendaftar dipindah ke alur bayar & dikirimi link pembayaran.");

        return self::SUCCESS;
    }
}
