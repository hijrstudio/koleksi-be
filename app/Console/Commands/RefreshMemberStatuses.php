<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\MemberRenewalReminder;
use App\Notifications\MembershipExpired;
use App\Services\MembershipService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Dijalankan harian oleh scheduler (routes/console.php).
 * Menonaktifkan member mode otomatis yang masa tenggangnya sudah habis, lalu memberi tahu member tsb.
 */
class RefreshMemberStatuses extends Command
{
    protected $signature = 'members:refresh-statuses
        {--date= : Tanggal acuan (YYYY-MM-DD), default hari ini — untuk simulasi}
        {--dry-run : Tampilkan saja, tidak menyimpan / mengirim apa pun}';

    protected $description = 'Hitung ulang status keanggotaan & nonaktifkan member yang belum daftar ulang';

    public function handle(MembershipService $membership): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date'))->startOfDay() : today();
        $deactivated = $notified = 0;

        // Hanya kandidat yang statusnya mungkin berubah: aktif tapi masa berlakunya sudah lewat
        Member::query()
            ->where('status_mode', Member::MODE_AUTO)
            ->where('status', Member::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('active_until')->orWhereDate('active_until', '<', $date))
            ->chunkById(200, function ($members) use ($membership, $date, &$deactivated, &$notified) {
                foreach ($members as $member) {
                    if ($this->option('dry-run')) {
                        $this->line("  {$member->noLambungLabel()} {$member->nama_lengkap} → tidak aktif (aktif s/d {$member->active_until?->toDateString()})");
                        $deactivated++;

                        continue;
                    }

                    $membership->refreshStatus($member, $date);

                    if ($member->isActive()) {
                        continue;
                    }

                    $deactivated++;

                    if ($member->paid_until && $this->markSent($member)) {
                        $member->notify(new MembershipExpired);
                        $notified++;
                    }
                }
            });

        $this->info(($this->option('dry-run') ? '[DRY RUN] ' : '')."{$deactivated} member dinonaktifkan, {$notified} notifikasi dikirim.");

        return self::SUCCESS;
    }

    /** Catat dulu (unik per periode) supaya notifikasi tidak terkirim dobel. */
    private function markSent(Member $member): bool
    {
        return MemberRenewalReminder::query()->insertOrIgnore([
            'member_id' => $member->id,
            'period_end' => $member->paid_until->toDateString(),
            'stage' => MemberRenewalReminder::STAGE_EXPIRED,
            'channels' => json_encode(filled($member->email) ? ['database', 'mail'] : ['database']),
            'sent_at' => now(),
        ]) === 1;
    }
}
