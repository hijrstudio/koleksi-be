<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Services\MembershipService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pemutihan tunggakan iuran (keputusan pengurus): semua periode yang dimulai sebelum tahun pemutihan dan belum
 * lunas dicatat "dibebaskan (pemutihan)". Yang ditagih kemudian hanya periode tahun pemutihan ke atas (mis. 2026-2027).
 *
 *   php artisan members:waive-arrears --dry-run      # lihat dampaknya dulu, tidak menyimpan apa pun
 *   php artisan members:waive-arrears                # jalankan (tahun dari config membership.arrears_cutoff_year)
 *   php artisan members:waive-arrears --before=2026
 *
 * Aman dijalankan ulang (periode yang sudah lunas/dibebaskan tidak disentuh). Member yang sedang punya QRIS pending
 * dilewati — QR itu masih memuat nominal tunggakan lama; jalankan ulang setelah QR-nya kedaluwarsa (±15 menit).
 * Perlu dijalankan lagi setelah import Excel yang menambah tagihan lama.
 */
class WaiveMemberArrears extends Command
{
    protected $signature = 'members:waive-arrears
        {--before= : Putihkan periode yang dimulai sebelum tahun ini (default config membership.arrears_cutoff_year)}
        {--dry-run : Simulasi saja, tidak menyimpan apa pun}';

    protected $description = 'Pemutihan tunggakan iuran member sebelum tahun pemutihan';

    public function handle(MembershipService $membership): int
    {
        $before = $this->option('before') !== null ? (int) $this->option('before') : $membership->arrearsCutoffYear();
        if (! $before || $before < 2000 || $before > (int) date('Y') + 1) {
            $this->error('Tahun pemutihan tidak valid. Isi --before=2026 atau MEMBERSHIP_ARREARS_CUTOFF_YEAR.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $stats = ['members' => 0, 'periods' => 0, 'inactive_to_active' => 0, 'active_to_inactive' => 0];
        $skipped = [];

        DB::beginTransaction();

        try {
            Member::query()->registered()->orderBy('id')->chunkById(200, function ($members) use ($membership, $before, &$stats, &$skipped) {
                foreach ($members as $member) {
                    if ($member->payments()->pending()->exists()) {
                        $skipped[] = $member->noLambungLabel().' '.$member->nama_lengkap;

                        continue;
                    }

                    $status = $member->status;
                    $count = $membership->waiveArrears($member, $before);
                    if ($count === 0) {
                        continue;
                    }

                    $stats['members']++;
                    $stats['periods'] += $count;
                    if ($status !== $member->status) {
                        $stats[$member->status === Member::STATUS_ACTIVE ? 'inactive_to_active' : 'active_to_inactive']++;
                    }
                }
            });

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Pemutihan dibatalkan: '.$e->getMessage());

            return self::FAILURE;
        }

        $label = $before.'-'.($before + 1);
        $this->info(($dryRun ? '[DRY RUN] ' : '')."Pemutihan tunggakan sebelum periode {$label}:");
        $this->table(['Hasil', 'Jumlah'], [
            ['Member yang tunggakannya diputihkan', $stats['members']],
            ['Periode diputihkan', $stats['periods']],
            ['Status berubah tidak aktif → aktif', $stats['inactive_to_active']],
            ['Status berubah aktif → tidak aktif', $stats['active_to_inactive']],
            ['Dilewati (QRIS pending)', count($skipped)],
        ]);

        if ($skipped) {
            $this->warn('Dilewati karena sedang punya QRIS pending — jalankan ulang perintah ini setelah QR kedaluwarsa:');
            foreach ($skipped as $s) {
                $this->line("  - {$s}");
            }
        }

        return self::SUCCESS;
    }
}
