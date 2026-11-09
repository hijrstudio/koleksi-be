<?php

namespace App\Services;

use App\Http\Requests\Cms\MemberRequest;
use App\Models\Member;
use App\Models\MemberVehicle;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Tambah & edit member oleh admin CMS (data hasil MemberRequest).
 *
 * - No. lambung lewat NoLambungAllocator (kunci + UNIQUE): kosong = nomor kosong berikutnya.
 * - Member baru langsung anggota resmi (registration_status approved). Kata sandi: link "atur kata sandi" ke e-mail
 *   (sampai diatur, kata sandi acak yang tidak diketahui siapa pun), atau dibuatkan admin sebagai kata sandi
 *   sementara (must_change_password: dasbor member menampilkan pengingat untuk menggantinya). Kata sandi tidak dicatat di log.
 * - Status keanggotaan tetap dihitung dari iuran (MembershipService), bukan diisi admin.
 * - Kendaraan yang diisi admin dianggap terverifikasi oleh admin tsb.
 * - Setiap perubahan dicatat di riwayat status member (siapa & field apa).
 */
class MemberAdminService
{
    private const PERSONAL = [
        'nama_lengkap', 'nama_panggilan', 'email', 'no_hp', 'tgl_lahir', 'jenis_kelamin', 'alamat',
        'city_code', 'district_code', 'village_code', 'tgl_bergabung', 'notes',
    ];

    private const VEHICLE = [
        'vin', 'plat_kode', 'plat_nomor', 'plat_seri', 'plat_status', 'vehicle_brand_id', 'vehicle_model_id', 'tahun', 'warna',
    ];

    /** Nama field untuk riwayat perubahan. */
    private const LABELS = [
        'no_lambung' => 'No. lambung', 'nama_lengkap' => 'nama lengkap', 'nama_panggilan' => 'nama panggilan',
        'email' => 'e-mail', 'no_hp' => 'No. HP', 'tgl_lahir' => 'tanggal lahir', 'jenis_kelamin' => 'jenis kelamin',
        'alamat' => 'alamat', 'city_code' => 'kota', 'district_code' => 'kecamatan', 'village_code' => 'kelurahan',
        'tgl_bergabung' => 'tanggal bergabung', 'notes' => 'catatan', 'has_vehicle' => 'kepemilikan kendaraan',
        'password' => 'kata sandi',
    ];

    public function __construct(
        private readonly NoLambungAllocator $noLambung,
        private readonly MembershipService $membership,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $admin): Member
    {
        $member = new Member;
        $member->fill(array_intersect_key($data, array_flip(self::PERSONAL)));
        $member->has_vehicle = (bool) $data['has_vehicle'];
        $this->applyPassword($member, $data);
        $member->forceFill([
            'registration_status' => Member::REG_APPROVED,
            'registered_at' => now(),
            'email_verified_at' => $member->email ? now() : null,   // e-mail dimasukkan & dicek admin
        ]);

        $save = fn () => DB::transaction(function () use ($member, $data, $admin) {
            $member->save();
            $member->refresh();   // default kolom (status, status_mode) dari database

            if ($member->has_vehicle) {
                $this->saveVehicle($member, $data, $admin);
            }

            $this->recordFirstDue($member, $data, $admin);
            $this->membership->refreshStatus($member, null, $admin, 'Member ditambahkan dari CMS');
            $this->membership->renumberInvoices($member);
            $this->log($member, $admin, 'Member ditambahkan dari CMS');
        });

        if (filled($data['no_lambung'] ?? null)) {
            $member->no_lambung = (int) $data['no_lambung'];
            $this->noLambung->change($member, $member->no_lambung, $save);
        } else {
            $this->noLambung->assign(function (int $number) use ($member, $save) {
                $member->no_lambung = $number;
                $save();
            });
        }

        $member->profile_completed_at = $member->isProfileComplete() ? now() : null;
        $member->save();

        $this->sendPasswordLink($member, $data);

        return $member;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Member $member, array $data, User $admin): Member
    {
        $oldNumber = $member->noLambungLabel();
        $number = (int) $data['no_lambung'];

        $save = fn () => DB::transaction(function () use ($member, $data, $admin, $number, $oldNumber) {
            $member->fill(array_intersect_key($data, array_flip(self::PERSONAL)));
            $member->has_vehicle = (bool) $data['has_vehicle'];
            $member->no_lambung = $number;
            if ($member->isDirty('email')) {
                $member->email_verified_at = $member->email ? now() : null;
            }
            if ($this->applyPassword($member, $data)) {
                $member->tokens()->delete();   // kata sandi diganti admin → keluar dari semua sesi login
            }

            $changed = array_keys(array_intersect_key($member->getDirty(), self::LABELS));
            $member->save();

            if ($member->has_vehicle && $this->saveVehicle($member, $data, $admin)) {
                $changed[] = 'kendaraan';
            }
            // has_vehicle = tidak: data kendaraan lama tidak dihapus (riwayat & verifikasi tetap ada)

            $member->profile_completed_at = $member->isProfileComplete() ? ($member->profile_completed_at ?? now()) : null;
            $member->save();

            if (in_array('no_lambung', $changed, true)) {
                $this->membership->renumberInvoices($member);
            }

            if ($changed !== []) {
                $fields = collect($changed)->map(fn (string $f) => self::LABELS[$f] ?? $f)->implode(', ');
                $this->log($member, $admin, "Data diubah dari CMS: {$fields}"
                    .(in_array('no_lambung', $changed, true) ? " (No. lambung {$oldNumber} → {$member->noLambungLabel()})" : ''));
            }
        });

        if ($number !== $member->no_lambung) {
            $this->noLambung->change($member, $number, $save);   // dikunci & ditolak kalau sudah dipakai
        } else {
            $save();
        }

        if ($this->sendPasswordLink($member, $data)) {
            $this->log($member, $admin, 'Link atur kata sandi dikirim ke e-mail member dari CMS');
        }

        return $member;
    }

    /**
     * Kata sandi buatan admin = sementara (member diingatkan untuk menggantinya). Member baru tanpa kata sandi admin
     * mendapat kata sandi acak yang tidak diketahui siapa pun. Mengembalikan true kalau kata sandi diganti admin.
     */
    private function applyPassword(Member $member, array $data): bool
    {
        if (($data['password_mode'] ?? null) === MemberRequest::PASSWORD_ADMIN) {
            $member->password = $data['password'];   // di-hash otomatis (cast 'hashed')
            $member->must_change_password = true;

            return true;
        }

        if (! $member->exists) {
            $member->password = Str::random(64);
            $member->must_change_password = false;
        }

        return false;
    }

    /** Kirim link atur kata sandi (password broker member) kalau dipilih. */
    private function sendPasswordLink(Member $member, array $data): bool
    {
        if (($data['password_mode'] ?? null) !== MemberRequest::PASSWORD_EMAIL || ! $member->email) {
            return false;
        }

        Password::broker('members')->sendResetLink(['email' => $member->email]);

        return true;
    }

    /** Simpan kendaraan utama. Mengembalikan true kalau ada yang berubah. */
    private function saveVehicle(Member $member, array $data, User $admin): bool
    {
        $vehicle = $member->primaryVehicle() ?? new MemberVehicle(['member_id' => $member->id, 'is_primary' => true]);

        // Field opsional yang dikosongkan di form ikut dikosongkan di DB
        $vehicle->fill(array_merge(array_fill_keys(self::VEHICLE, null), array_intersect_key($data, array_flip(self::VEHICLE))));
        if (! $vehicle->vehicle_brand_id) {
            $vehicle->vehicle_model_id = null;
        }

        if ($vehicle->exists && ! $vehicle->isDirty()) {
            return false;
        }

        // Data kendaraan dari admin = sudah dicek admin
        if (! $vehicle->exists || $vehicle->isDirty(MemberVehicle::SENSITIVE)) {
            $vehicle->forceFill([
                'verification_status' => MemberVehicle::VERIFIED,
                'verified_by' => $admin->id,
                'verified_at' => now(),
                'rejection_reason' => null,
            ]);
        }

        $vehicle->save();

        return true;
    }

    private function recordFirstDue(Member $member, array $data, User $admin): void
    {
        $term = $this->membership->currentTerm($member);

        match ($data['first_due']) {
            MemberRequest::FIRST_DUE_PAID => $this->membership->markPaid(
                $this->membership->invoiceFor($member, $term), $data['payment_method'], now(), $admin, 'Dicatat admin saat menambah member',
            ),
            MemberRequest::FIRST_DUE_WAIVED => $this->membership->waive($member, $term, $admin, 'Dibebaskan admin saat menambah member'),
            default => $this->membership->invoiceFor($member, $term),
        };
    }

    private function log(Member $member, User $admin, string $reason): void
    {
        $member->statusLogs()->create([
            'from_status' => $member->status,
            'to_status' => $member->status,
            'mode' => $member->status_mode,
            'reason' => Str::limit($reason, 480),
            'changed_by' => $admin->id,
        ]);
    }
}
