<?php

namespace Database\Seeders;

use App\Models\Member;
use App\Services\MembershipService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 3 member uji (no. lambung 901–903), semuanya TIDAK AKTIF — untuk testing login, profil & pembayaran iuran.
 * Sengaja tidak dipanggil DatabaseSeeder (jangan sampai masuk production):
 *
 *   php artisan db:seed --class=TestMemberSeeder
 *
 * Dijalankan ulang = data uji di-reset ke kondisi awal (iuran, status, profil, foto, kendaraan, token login).
 *
 *   #901  belum pernah bayar     — bergabung hari ini, tagihan periode pertama belum lunas
 *   #902  lewat masa tenggang    — 2025-2026 lunas, 2026-2027 belum dibayar
 *   #903  menunggak 2 periode    — 2024-2025 lunas, 2025-2026 & 2026-2027 belum dibayar
 */
class TestMemberSeeder extends Seeder
{
    public const PASSWORD = 'Koleksi123!';

    public function run(MembershipService $membership): void
    {
        $today = today();

        $members = [
            [901, 'Syauri (Test)', 'syauri04@gmail.com', $today->copy(), []],
            [902, 'Rifan (Test)', 'rifan@hijrstudio.com', Carbon::create($today->year - 1, 8, 1), [$today->year - 1]],
            [903, 'Hijr Studio (Test)', 'hijrstudio@gmail.com', Carbon::create($today->year - 2, 9, 1), [$today->year - 2]],
        ];

        DB::transaction(function () use ($members, $membership, $today) {
            foreach ($members as [$lambung, $name, $email, $joined, $paidYears]) {
                $member = Member::withTrashed()->firstOrNew(['no_lambung' => $lambung]);
                $member->exists && $this->reset($member);

                $member->fill([
                    'nama_lengkap' => $name,
                    'email' => $email,
                    'no_hp' => null,
                    'tgl_bergabung' => $joined,
                    'has_vehicle' => false,
                    'notes' => 'Data uji (TestMemberSeeder) — hapus sebelum go-live.',
                    'password' => self::PASSWORD,
                    'must_change_password' => false,
                ]);
                $member->deleted_at = null;
                $member->save();

                foreach ($paidYears as $year) {
                    $due = $membership->invoiceFor($member, $membership->termForYear($member, $year));
                    $membership->markPaid($due, 'transfer', $due->period_start->copy(), null, 'Data uji');
                }

                // Semua periode yang belum lunas sampai periode berjalan → tagihan unpaid (untuk testing bayar)
                $term = $paidYears
                    ? $membership->termForYear($member, max($paidYears) + 1)
                    : $membership->currentTerm($member);
                while ($term->start->lte($today)) {
                    $membership->invoiceFor($member, $term);
                    $term = $membership->termForYear($member, $term->start->year + 1);
                }

                $membership->refreshStatus($member, null, null, 'Data uji: belum bayar iuran');

                $this->command?->line(sprintf('  #%d %-22s %-8s paid_until=%s tagihan_unpaid=%d',
                    $lambung, $email, $member->status, $member->paid_until?->toDateString() ?? '-',
                    $member->dues()->where('status', 'unpaid')->count()));
            }
        });

        $this->command?->info('Password semua member uji: '.self::PASSWORD);
    }

    /** Hapus semua jejak testing sebelumnya supaya kondisi awal selalu sama. */
    private function reset(Member $member): void
    {
        $member->tokens()->delete();
        if ($member->photo_profile && $member->photo_profile !== Member::DEFAULT_PHOTO) {
            Storage::disk(Member::PHOTO_DISK)->delete($member->photo_profile);
        }
        $member->dues()->delete();
        $member->statusLogs()->delete();
        $member->renewalReminders()->delete();
        $member->vehicles->each(function ($vehicle) {
            $vehicle->documents->each->delete(); // ikut menghapus file
            $vehicle->delete();
        });

        $member->forceFill([
            'nama_panggilan' => null, 'photo_profile' => null, 'tgl_lahir' => null, 'jenis_kelamin' => null,
            'alamat' => null, 'city_code' => null, 'district_code' => null, 'village_code' => null,
            'status' => Member::STATUS_INACTIVE, 'status_mode' => Member::MODE_AUTO,
            'paid_until' => null, 'active_until' => null,
            'profile_completed_at' => null, 'last_login_at' => null,
        ])->save();
    }
}
