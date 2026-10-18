<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Services\MemberRegistrationService;
use Illuminate\Console\Command;

/**
 * Hapus pendaftar yang tidak memverifikasi e-mail dalam N hari (membership.registration.unverified_ttl_days),
 * beserta dokumen kendaraannya — supaya data pribadi & file tidak menumpuk dan e-mail / No. HP / kendaraannya
 * bisa dipakai mendaftar lagi. Pendaftaran yang ditolak tetap disimpan sebagai riwayat review.
 */
class PruneMemberRegistrations extends Command
{
    protected $signature = 'members:prune-registrations {--dry-run : Tampilkan saja, tidak menghapus apa pun}';

    protected $description = 'Hapus pendaftar member yang tidak memverifikasi e-mail';

    public function handle(MemberRegistrationService $registrations): int
    {
        $before = now()->subDays(config('membership.registration.unverified_ttl_days'));
        $count = 0;

        Member::withTrashed()
            ->registration(Member::REG_UNVERIFIED)
            ->where('registered_at', '<', $before)
            ->chunkById(100, function ($members) use ($registrations, &$count) {
                foreach ($members as $member) {
                    if ($this->option('dry-run')) {
                        $this->line("  {$member->email} (daftar {$member->registered_at?->toDateTimeString()})");
                    } else {
                        $registrations->purge($member);
                    }
                    $count++;
                }
            });

        $this->info(($this->option('dry-run') ? '[DRY RUN] ' : '')."{$count} pendaftar belum verifikasi dihapus.");

        return self::SUCCESS;
    }
}
