<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberVehicle;
use App\Models\MemberVehicleDocument;
use App\Models\User;
use App\Notifications\MemberRegistrationApproved;
use App\Notifications\MemberRegistrationRejected;
use App\Notifications\NewMemberRegistration;
use App\Notifications\VerifyMemberRegistrationEmail;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Alur pendaftaran member baru lewat website:
 *
 *   register()     form /daftar          → members (unverified) + kendaraan & dokumen, kirim link verifikasi e-mail
 *   verifyEmail()  klik link di e-mail    → pending, kabari pengurus (lonceng CMS + e-mail)
 *   approve()      pengurus di CMS        → approved + No. lambung + tgl bergabung, e-mail "bayar iuran pertama"
 *   reject()       pengurus di CMS        → rejected + alasan, e-mail ke pendaftar
 *
 * Setelah disetujui, member login dan membayar iuran lewat kartu iuran di dasbor (QRIS YODU) — sama dengan
 * daftar ulang: BillingResolver melihat paid_until kosong (state "unpaid") lalu menagih periode pertama,
 * PaymentService menandai lunas, MembershipService mengaktifkan member.
 */
class MemberRegistrationService
{
    public function __construct(
        private MemberProfileService $profiles,
        private MembershipService $membership,
    ) {}

    /**
     * @param  array<string, mixed>  $data  hasil validasi RegisterMemberRequest
     * @param  list<UploadedFile>  $documents
     */
    public function register(array $data, array $documents, ?string $ip): Member
    {
        $member = DB::transaction(function () use ($data, $documents, $ip) {
            // Pendaftaran lama yang tidak diverifikasi / ditolak dengan e-mail, No. HP, atau kendaraan yang sama
            // tidak boleh menghalangi pendaftaran baru → dihapus permanen beserta dokumennya.
            $this->purgeStale($data);

            $member = new Member([
                'email' => $data['email'],
                'password' => $data['password'],
                'nama_lengkap' => $data['nama_lengkap'],
                'no_hp' => $data['no_hp'],
                'has_vehicle' => (bool) $data['has_vehicle'],
                'must_change_password' => false,   // kata sandi dibuat sendiri oleh pendaftar
            ]);
            $member->forceFill([
                'status' => Member::STATUS_INACTIVE,
                'status_mode' => Member::MODE_AUTO,
                'registration_status' => Member::REG_UNVERIFIED,
                'registered_at' => now(),
                'registration_ip' => $ip,
                'heard_from' => $data['heard_from'] ?? null,
            ])->save();

            // Data pribadi, wilayah, kendaraan & dokumen: logika yang sama dengan "Ubah profil" (termasuk
            // pembersihan file kalau gagal). Kendaraan baru otomatis berstatus verifikasi "pending".
            return $this->profiles->update($member, $data, $member->has_vehicle ? $documents : []);
        });

        $member->notify(new VerifyMemberRegistrationEmail);

        return $member;
    }

    /**
     * Kirim ulang link verifikasi. Diam-diam tidak melakukan apa-apa kalau e-mail tidak terdaftar / sudah
     * terverifikasi, supaya endpoint tidak bisa dipakai menebak e-mail yang terdaftar.
     */
    public function resendVerification(string $email): void
    {
        $member = Member::query()
            ->registration(Member::REG_UNVERIFIED)
            ->where('email', $email)
            ->first();

        $member?->notify(new VerifyMemberRegistrationEmail);
    }

    /**
     * Link verifikasi diklik (signature & masa berlaku sudah dicek controller).
     *
     * @return 'verified'|'already'|'invalid'
     */
    public function verifyEmail(Member $member, string $hash): string
    {
        if (! hash_equals(sha1((string) $member->email), $hash)) {
            return 'invalid';
        }

        $verified = DB::transaction(function () use ($member) {
            $member = Member::query()->lockForUpdate()->find($member->id);
            if ($member?->registration_status !== Member::REG_UNVERIFIED) {
                return null;
            }

            $member->forceFill([
                'email_verified_at' => now(),
                'registration_status' => Member::REG_PENDING,
            ])->save();

            return $member;
        });

        if (! $verified) {
            // Klik ulang link yang sama setelah berhasil → tetap tampilkan sukses, bukan error
            return in_array($member->refresh()->registration_status, [Member::REG_PENDING, Member::REG_APPROVED], true)
                ? 'already'
                : 'invalid';
        }

        $this->notifyAdmins($verified);

        return 'verified';
    }

    /**
     * Setujui pendaftaran: beri No. lambung berikutnya, tanggal bergabung = hari ini (patokan periode iuran),
     * kendaraan & dokumen dianggap sudah diverifikasi saat review.
     *
     * @throws ValidationException
     */
    public function approve(Member $member, User $admin): Member
    {
        // Kunci global supaya dua admin yang menyetujui bersamaan tidak mendapat No. lambung yang sama
        // (kolom no_lambung juga unique di DB sebagai pengaman terakhir).
        try {
            $member = Cache::lock('members:assign-no-lambung', 10)->block(5, fn () => DB::transaction(function () use ($member, $admin) {
                $member = $this->lockPending($member);

                $member->forceFill([
                    'no_lambung' => (int) Member::withTrashed()->max('no_lambung') + 1,
                    'tgl_bergabung' => today(),
                    'registration_status' => Member::REG_APPROVED,
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                    'review_note' => null,
                ])->save();

                $member->vehicles()->where('verification_status', MemberVehicle::PENDING)->update([
                    'verification_status' => MemberVehicle::VERIFIED,
                    'verified_by' => $admin->id,
                    'verified_at' => now(),
                ]);

                // Belum ada iuran lunas → tetap tidak aktif sampai iuran pertama dibayar
                $this->membership->refreshStatus($member);
                $member->statusLogs()->create([
                    'from_status' => null,
                    'to_status' => $member->status,
                    'mode' => $member->status_mode,
                    'reason' => 'Pendaftaran disetujui, menunggu pembayaran iuran pertama',
                    'changed_by' => $admin->id,
                ]);

                return $member;
            }));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['registration' => 'Sedang ada persetujuan lain yang diproses. Coba lagi.']);
        }

        $member->notify(new MemberRegistrationApproved);

        return $member;
    }

    /**
     * @throws ValidationException
     */
    public function reject(Member $member, User $admin, string $reason): Member
    {
        $member = DB::transaction(function () use ($member, $admin, $reason) {
            $member = $this->lockPending($member);

            $member->forceFill([
                'registration_status' => Member::REG_REJECTED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => $reason,
            ])->save();

            $member->vehicles()->where('verification_status', MemberVehicle::PENDING)->update([
                'verification_status' => MemberVehicle::REJECTED,
                'verified_by' => $admin->id,
                'verified_at' => now(),
                'rejection_reason' => $reason,
            ]);

            return $member;
        });

        $member->notify(new MemberRegistrationRejected($reason));

        return $member;
    }

    /**
     * Hapus permanen pendaftar (beserta file dokumen). Dipakai untuk pendaftaran usang & members:prune-registrations.
     */
    public function purge(Member $member): void
    {
        DB::transaction(function () use ($member) {
            // Per model supaya file fisik dokumen ikut terhapus (event deleted di MemberVehicleDocument)
            MemberVehicleDocument::query()
                ->whereIn('member_vehicle_id', $member->vehicles()->select('id'))
                ->get()
                ->each(fn (MemberVehicleDocument $doc) => $doc->delete());

            $member->forceDelete();
        });
    }

    /** Admin CMS yang boleh mereview pendaftaran. */
    public function reviewers(): Collection
    {
        $admins = User::role('super-admin')->get();

        try {
            $admins = $admins->merge(User::permission('members.review')->get());
        } catch (PermissionDoesNotExist) {
            // RoleSeeder belum dijalankan ulang → cukup super-admin
        }

        return $admins->unique('id')->values();
    }

    private function notifyAdmins(Member $member): void
    {
        Notification::send($this->reviewers(), new NewMemberRegistration($member));

        $emails = config('membership.registration.admin_emails');
        if ($emails !== []) {
            Notification::route('mail', $emails)->notify(new NewMemberRegistration($member));
        }
    }

    /** @throws ValidationException */
    private function lockPending(Member $member): Member
    {
        $locked = Member::query()->lockForUpdate()->find($member->id);

        if ($locked?->registration_status !== Member::REG_PENDING) {
            throw ValidationException::withMessages([
                'registration' => 'Pendaftaran ini sudah direview atau belum memverifikasi e-mail.',
            ]);
        }

        return $locked;
    }

    /** @param  array<string, mixed>  $data */
    private function purgeStale(array $data): void
    {
        $stale = Member::withTrashed()
            ->whereIn('registration_status', Member::REG_STALE)
            ->where(function ($q) use ($data) {
                $q->where('email', $data['email'])->orWhere('no_hp', $data['no_hp']);

                if ($data['has_vehicle']) {
                    $q->orWhereHas('vehicles', fn ($v) => $v->where('vin', $data['vin'])
                        ->orWhere('plat_full', $data['plat_kode'].$data['plat_nomor'].($data['plat_seri'] ?? '')));
                }
            })
            ->get();

        $stale->each(fn (Member $m) => $this->purge($m));
    }
}
