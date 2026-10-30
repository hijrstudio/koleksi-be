<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberVehicleDocument;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\MemberRegistrationActivated;
use App\Notifications\MemberRegistrationPaymentLink;
use App\Notifications\NewMemberRegistration;
use App\Notifications\VerifyMemberRegistrationEmail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Alur pendaftaran member baru lewat website (tanpa review pengurus):
 *
 *   register()     form /daftar            → members (unverified) + kendaraan & dokumen, kirim link verifikasi e-mail
 *   verifyEmail()  klik link di e-mail      → awaiting_payment + batas bayar; FE langsung membuka halaman bayar
 *   (bayar)        halaman /daftar/bayar     → QRIS iuran pertama (Api\RegistrationPaymentController + PaymentService)
 *   activate()     iuran pertama lunas       → approved: No. lambung (NoLambungAllocator), tgl bergabung, bisa login
 *
 * Pendaftar unverified / awaiting_payment tidak bisa login dan tidak tampil di daftar member CMS. Yang tidak membayar
 * sampai batasnya dihapus members:prune-registrations.
 */
class MemberRegistrationService
{
    public function __construct(
        private MemberProfileService $profiles,
        private MembershipService $membership,
        private NoLambungAllocator $noLambung,
    ) {}

    /**
     * @param  array<string, mixed>  $data  hasil validasi RegisterMemberRequest
     * @param  list<UploadedFile>  $documents
     */
    public function register(array $data, array $documents, ?string $ip): Member
    {
        $member = DB::transaction(function () use ($data, $documents, $ip) {
            // Pendaftaran lama (belum verifikasi / belum bayar / ditolak) dengan e-mail, No. HP, atau kendaraan yang
            // sama tidak boleh menghalangi pendaftaran baru → dihapus permanen beserta dokumennya.
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
     * Kirim ulang link: verifikasi (belum verifikasi) atau bayar (belum bayar, batas bayar diperpanjang).
     * Diam-diam tidak melakukan apa-apa kalau e-mail tidak terdaftar / sudah aktif, supaya endpoint tidak bisa
     * dipakai menebak e-mail yang terdaftar.
     */
    public function resend(string $email): void
    {
        $member = Member::query()
            ->whereIn('registration_status', [Member::REG_UNVERIFIED, Member::REG_AWAITING_PAYMENT])
            ->where('email', $email)
            ->first();

        if ($member?->isAwaitingPayment()) {
            $member->forceFill(['payment_due_at' => $this->paymentDeadline()])->save();
            $member->notify(new MemberRegistrationPaymentLink);

            return;
        }

        $member?->notify(new VerifyMemberRegistrationEmail);
    }

    /**
     * Link verifikasi diklik (signature & masa berlaku sudah dicek controller).
     *
     * @return 'verified'|'already'|'active'|'invalid'
     *                                                 verified/already → lanjut ke halaman bayar; active → sudah anggota, arahkan login
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
                'registration_status' => Member::REG_AWAITING_PAYMENT,
                'payment_due_at' => $this->paymentDeadline(),
            ])->save();

            return $member;
        });

        if ($verified) {
            return 'verified';
        }

        // Klik ulang link yang sama → tetap lanjut (halaman bayar / login), bukan error
        return match ($member->refresh()->registration_status) {
            Member::REG_AWAITING_PAYMENT => 'already',
            Member::REG_APPROVED => 'active',
            default => 'invalid',
        };
    }

    /** Halaman bayar iuran pendaftaran di FE, dengan token yang berlaku sampai batas bayar. */
    public function paymentUrl(Member $member): string
    {
        $expires = $member->payment_due_at ?? $this->paymentDeadline();

        return config('membership.registration.payment_url').'?'.http_build_query([
            'token' => RegistrationPaymentToken::make($member, $expires),
        ]);
    }

    /**
     * Iuran pertama lunas → jadikan anggota resmi. Dipanggil PaymentService di DALAM transaksi pembayaran
     * (setelah tagihan ditandai lunas, sebelum status keanggotaan dihitung ulang). Idempoten.
     */
    public function activate(Member $member): bool
    {
        $member = Member::query()->lockForUpdate()->find($member->id);
        if (! $member?->isAwaitingPayment()) {
            return false;
        }

        // Tanggal bergabung = awal periode iuran pertama yang dibayar → periode tahunan cocok dengan tagihannya
        $firstPeriod = $member->dues()->whereNotNull('paid_at')->min('period_start');

        $this->noLambung->assign(function (int $number) use ($member, $firstPeriod) {
            $member->forceFill([
                'no_lambung' => $number,
                'tgl_bergabung' => $firstPeriod ? Carbon::parse($firstPeriod) : today(),
                'registration_status' => Member::REG_APPROVED,
                'payment_due_at' => null,
            ])->save();
        });

        $this->membership->renumberInvoices($member);

        DB::afterCommit(function () use ($member) {
            $member->notify(new MemberRegistrationActivated);
            $this->notifyAdmins($member);
        });

        return true;
    }

    /**
     * Hapus permanen pendaftar (beserta file dokumen & riwayat QR yang belum dibayar).
     * Dipakai untuk pendaftaran usang & members:prune-registrations.
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

    /**
     * Pembayaran pendaftar yang masih bisa berubah jadi lunas (QR belum kedaluwarsa) atau sudah lunas —
     * pendaftar seperti ini TIDAK boleh dihapus, supaya uang yang masuk tidak kehilangan pemiliknya.
     */
    public function hasPaymentInFlight(Member $member): bool
    {
        return $member->payments()
            ->where(fn ($q) => $q->where('status', Payment::SUCCESS)
                ->orWhere(fn ($q) => $q->where('status', Payment::PENDING)->where('expired_at', '>', now()->subMinutes(5))))
            ->exists();
    }

    /** Admin CMS yang dikabari anggota baru: super-admin & yang boleh melihat daftar member. */
    public function admins(): Collection
    {
        return User::role('super-admin')->get()
            ->merge(User::permission('members.view')->get())
            ->unique('id')
            ->values();
    }

    public function paymentDeadline(): Carbon
    {
        return now()->addHours((int) config('membership.registration.payment_ttl_hours'));
    }

    private function notifyAdmins(Member $member): void
    {
        Notification::send($this->admins(), new NewMemberRegistration($member));

        $emails = config('membership.registration.admin_emails');
        if ($emails !== []) {
            Notification::route('mail', $emails)->notify(new NewMemberRegistration($member));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function purgeStale(array $data): void
    {
        $plat = ($data['plat_kode'] ?? '').($data['plat_nomor'] ?? '').($data['plat_seri'] ?? '');

        $stale = Member::withTrashed()
            ->whereIn('registration_status', Member::REG_STALE)
            ->where(function ($q) use ($data, $plat) {
                $q->where('email', $data['email'])->orWhere('no_hp', $data['no_hp']);

                if ($data['has_vehicle']) {
                    $q->orWhereHas('vehicles', fn ($v) => $v->where('plat_full', $plat)
                        ->when(filled($data['vin'] ?? null), fn ($v) => $v->orWhere('vin', $data['vin'])));
                }
            })
            ->get();

        foreach ($stale as $member) {
            if ($member->isAwaitingPayment() && $this->hasPaymentInFlight($member)) {
                throw ValidationException::withMessages([
                    'email' => 'Pendaftaran dengan data ini sedang menunggu konfirmasi pembayaran. Coba lagi beberapa menit lagi.',
                ]);
            }

            $this->purge($member);
        }
    }
}
