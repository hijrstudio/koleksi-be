<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\MemberRenewalReminder;
use App\Notifications\MembershipRenewalReminder;
use App\Services\MembershipService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Dijalankan harian oleh scheduler (routes/console.php).
 *
 * Mengirim pengingat daftar ulang H-30, H-7 dan hari-H (config membership.reminder_days)
 * sebelum jatuh tempo (= ulang tahun keanggotaan). Tiap tahap hanya dikirim sekali per periode;
 * kalau scheduler sempat mati beberapa hari, hanya tahap paling mendesak yang dikirim.
 */
class SendMembershipRenewalReminders extends Command
{
    protected $signature = 'members:send-renewal-reminders
        {--date= : Tanggal acuan (YYYY-MM-DD), default hari ini — untuk simulasi}
        {--dry-run : Tampilkan saja, tidak mengirim apa pun}';

    protected $description = 'Kirim notifikasi pengingat daftar ulang ke member yang mendekati jatuh tempo';

    public function handle(MembershipService $membership): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date'))->startOfDay() : today();
        $stages = collect(config('membership.reminder_days'))->map(fn ($d) => (int) $d)->sort()->values();
        $sent = 0;

        Member::query()
            ->where('status_mode', Member::MODE_AUTO)           // status manual (mis. pengurus) tidak diingatkan
            ->where('status', Member::STATUS_ACTIVE)
            ->whereNotNull('paid_until')
            // jatuh tempo (paid_until + 1) ≤ hari ini + tahap terjauh
            ->whereDate('paid_until', '<', $date->copy()->addDays($stages->max()))
            ->whereDate('active_until', '>=', $date)
            ->chunkById(200, function ($members) use ($membership, $date, $stages, &$sent) {
                foreach ($members as $member) {
                    $daysLeft = $member->daysUntilRenewal($date);
                    $stage = $stages->first(fn ($s) => $daysLeft <= $s);

                    if ($stage === null) {
                        continue;
                    }

                    if ($this->option('dry-run')) {
                        $this->line("  {$member->noLambungLabel()} {$member->nama_lengkap} → H-{$daysLeft} (tahap {$stage})");
                        $sent++;

                        continue;
                    }

                    if (! $this->markSent($member, (string) $stage)) {
                        continue; // tahap ini sudah pernah dikirim
                    }

                    $member->notify(new MembershipRenewalReminder($membership->renewalTerm($member), max($daysLeft, 0)));
                    $sent++;
                }
            });

        $this->info(($this->option('dry-run') ? '[DRY RUN] ' : '')."{$sent} pengingat daftar ulang dikirim.");

        return self::SUCCESS;
    }

    /** Catat dulu (unik per member + periode + tahap) supaya aman dari kiriman dobel. */
    private function markSent(Member $member, string $stage): bool
    {
        return MemberRenewalReminder::query()->insertOrIgnore([
            'member_id' => $member->id,
            'period_end' => $member->paid_until->toDateString(),
            'stage' => $stage,
            'channels' => json_encode(filled($member->email) ? ['database', 'mail'] : ['database']),
            'sent_at' => now(),
        ]) === 1;
    }
}
