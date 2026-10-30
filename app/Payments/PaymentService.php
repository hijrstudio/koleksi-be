<?php

namespace App\Payments;

use App\Models\Member;
use App\Models\MemberDue;
use App\Models\Payment;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Data\TxStatus;
use App\Services\MemberRegistrationService;
use App\Services\MembershipService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Alur pembayaran iuran via QRIS:
 *   checkout() → QR dibuat di gateway   (klik "Bayar Iuran")
 *   refresh()  → cek status, maks 1x / 5 detik per transaksi   (polling FE & scheduler)
 *   apply()    → terapkan status dari callback / cek status: payments → member_dues → members
 *
 * Dipakai anggota (dasbor) dan pendaftar baru yang belum membayar (halaman /daftar/bayar): iuran pertama yang lunas
 * mengaktifkan pendaftar (MemberRegistrationService::activate, No. lambung diberikan saat itu).
 *
 * Tiga jalur deteksi (callback, polling FE, scheduler payments:reconcile) → tidak ada pembayaran yang terlewat.
 */
class PaymentService
{
    /** Jeda minimal antar cek status ke gateway per transaksi (FE polling tiap 4 detik). */
    private const CHECK_THROTTLE_SECONDS = 5;

    /** QR dipakai ulang hanya kalau masih berlaku minimal selama ini (member perlu waktu untuk scan). */
    private const REUSE_MIN_SECONDS = 60;

    public function __construct(
        private PaymentGateway $gateway,
        private BillingResolver $billing,
        private MembershipService $membership,
        private MemberRegistrationService $registrations,
    ) {}

    /**
     * Klik "Bayar Iuran" → buat (atau pakai ulang) QRIS untuk semua tagihan yang sedang ditagih.
     * Idempoten: klik ganda / refresh mengembalikan QR yang sama selama tagihan & nominal tidak berubah.
     *
     * @throws ValidationException
     */
    public function checkout(Member $member): Payment
    {
        try {
            return Cache::lock("payments:checkout:{$member->id}", 30)
                ->block(10, fn () => $this->checkoutLocked($member));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['payment' => 'Pembayaran sedang diproses. Coba lagi sebentar.']);
        }
    }

    private function checkoutLocked(Member $member): Payment
    {
        $dues = $this->billing->payableDues($member);

        if ($dues->isEmpty()) {
            throw ValidationException::withMessages(['payment' => 'Tidak ada tagihan iuran saat ini.']);
        }

        $amount = (int) $dues->sum('amount');
        if ($amount <= 0) {
            throw ValidationException::withMessages(['payment' => 'Nominal iuran belum diatur pengurus.']);
        }

        // QR lama yang masih pending
        $open = $member->payments()->pending()->with('items')->latest('id')->first();
        if ($open) {
            $sameBill = $open->amount === $amount
                && $open->items->pluck('member_due_id')->sort()->values()->all() === $dues->pluck('id')->sort()->values()->all();

            if ($sameBill && $open->expired_at?->gt(now()->addSeconds(self::REUSE_MIN_SECONDS))) {
                return $open->load('dues');
            }

            // Mungkin sudah dibayar barusan → cek dulu sebelum dibatalkan
            $open = $this->refresh($open, force: true);
            if ($open->status === Payment::SUCCESS) {
                throw ValidationException::withMessages(['payment' => 'Pembayaran sebelumnya sudah berhasil. Muat ulang halaman.']);
            }
            if ($open->isPending()) {
                rescue(fn () => $this->gateway->cancelQr($open), report: true);
                $open->update(['status' => Payment::CANCELLED, 'failure_reason' => 'Diganti QR baru']);
            }
        }

        $payment = DB::transaction(function () use ($member, $dues, $amount) {
            $payment = Payment::create([
                'member_id' => $member->id,
                'gateway' => $this->gateway->name(),
                // unik global (YODU mensyaratkan unik per hari), maks 64: KLK0007-261012170105-A1B2C3;
                // pendaftar yang belum punya No. lambung memakai ID pendaftaran: KLKR00123-...
                'merchant_ref' => sprintf('KLK%s-%s-%s',
                    $member->no_lambung === null ? 'R'.str_pad((string) $member->id, 5, '0', STR_PAD_LEFT) : sprintf('%04d', $member->no_lambung),
                    now('Asia/Jakarta')->format('ymdHis'), Str::upper(Str::random(6))),
                'amount' => $amount,
                'status' => Payment::PENDING,
            ]);

            foreach ($dues as $due) {
                $payment->items()->create(['member_due_id' => $due->id, 'amount' => $due->amount]);
            }

            return $payment;
        });

        try {
            $qr = $this->gateway->createQr($payment);
        } catch (Throwable $e) {
            $payment->update(['status' => Payment::FAILED, 'failure_reason' => Str::limit($e->getMessage(), 500)]);
            Log::error('Create QRIS gagal', ['ref' => $payment->merchant_ref, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages(['payment' => 'Gagal membuat QRIS. Silakan coba lagi.']);
        }

        $payment->update([
            'gateway_ref' => $qr->gatewayRef,
            'gateway_qr_id' => $qr->gatewayQrId,
            'qr_string' => $qr->qrString,
            'expired_at' => $qr->expiredAt,
            'request_payload' => $qr->request,
            'response_payload' => $qr->response,
        ]);

        return $payment->load('dues');
    }

    /**
     * Status terkini (polling FE & scheduler). Cek ke gateway maksimal tiap 5 detik per transaksi.
     */
    public function refresh(Payment $payment, bool $force = false): Payment
    {
        if (! $payment->isPending()) {
            return $payment;
        }

        $pastExpiry = $payment->expired_at?->lt(now()->subMinute()) ?? false;

        // Sebelum ditandai expired, selalu cek terakhir (member bisa membayar di detik terakhir)
        $shouldCheck = $force || $pastExpiry || ! $payment->last_checked_at
            || $payment->last_checked_at->lt(now()->subSeconds(self::CHECK_THROTTLE_SECONDS));

        if ($shouldCheck) {
            $payment->forceFill(['last_checked_at' => now()])->save();

            if ($tx = $this->gateway->checkStatus($payment)) {
                $this->apply($tx);
            }

            $payment->refresh();
        }

        // QR lewat masa berlaku + 1 menit, sudah dicek, tetap belum dibayar → expired.
        // Gateway tidak bisa dihubungi (checkStatus null) → tetap pending, dicek ulang oleh scheduler.
        if ($payment->isPending() && $pastExpiry && ($tx ?? null) !== null) {
            $payment->update(['status' => Payment::EXPIRED]);
        }

        return $payment;
    }

    /**
     * Terapkan status transaksi. Idempoten & aman dipanggil bersamaan (lockForUpdate).
     * SUCCESS dari CALLBACK tidak langsung dipercaya: dikonfirmasi dulu ke API cek status.
     */
    public function apply(TxStatus $tx): bool
    {
        if ($tx->merchantRef === '') {
            return false;
        }

        if ($tx->fromCallback && $tx->status === TxStatus::SUCCESS) {
            $payment = Payment::where('merchant_ref', $tx->merchantRef)->first();
            if (! $payment) {
                Log::warning('Callback untuk merchant_ref tidak dikenal', ['ref' => $tx->merchantRef]);

                return false;
            }

            if ($payment->status === Payment::SUCCESS) {
                return true; // callback ulang (YODU mencoba s/d 3x) → sudah diproses
            }

            $confirmed = $this->gateway->checkStatus($payment);
            if (! $confirmed || $confirmed->status !== TxStatus::SUCCESS) {
                Log::warning('Callback SUCCESS tidak terkonfirmasi cek status', ['ref' => $tx->merchantRef]);
                $payment->update(['last_callback' => $tx->raw ?: null]);

                return false;
            }

            // Data cek status jadi acuan; rincian dari callback hanya dipakai kalau kosong
            $tx = new TxStatus(
                merchantRef: $tx->merchantRef,
                status: TxStatus::SUCCESS,
                payAmount: $confirmed->payAmount ?? $tx->payAmount,
                netAmount: $confirmed->netAmount ?? $tx->netAmount,
                mdrAmount: $confirmed->mdrAmount ?? $tx->mdrAmount,
                handlingFee: $confirmed->handlingFee ?? $tx->handlingFee,
                gatewayRef: $confirmed->gatewayRef ?? $tx->gatewayRef,
                channel: $confirmed->channel ?? $tx->channel,
                paidAt: $confirmed->paidAt ?? $tx->paidAt,
                rawCode: $confirmed->rawCode,
                raw: $tx->raw,
            );
        }

        return DB::transaction(function () use ($tx) {
            /** @var Payment|null $payment */
            $payment = Payment::where('merchant_ref', $tx->merchantRef)->lockForUpdate()->first();
            if (! $payment) {
                return false;
            }

            if ($tx->raw) {
                $payment->last_callback = $tx->raw;
            }
            $payment->gateway_ref ??= $tx->gatewayRef;
            $payment->payment_channel = $tx->channel ?? $payment->payment_channel;

            // Sudah lunas → abaikan duplikat; refund dicatat untuk ditindaklanjuti admin
            if ($payment->status === Payment::SUCCESS) {
                if ($tx->status === TxStatus::REFUNDED) {
                    $payment->status = Payment::REFUNDED;
                    Log::warning('Pembayaran iuran di-refund gateway', ['ref' => $payment->merchant_ref]);
                }
                $payment->save();

                return true;
            }

            switch ($tx->status) {
                case TxStatus::SUCCESS:
                    if ($tx->payAmount !== null && $tx->payAmount !== $payment->amount) {
                        $payment->failure_reason = "Nominal tidak cocok: gateway {$tx->payAmount}, sistem {$payment->amount}";
                        $payment->save();
                        Log::critical('Nominal pembayaran tidak cocok', ['ref' => $payment->merchant_ref]);

                        return false;
                    }

                    // Diterima juga kalau sebelumnya sudah ditandai expired/cancelled lokal
                    // (member membayar tepat di detik terakhir).
                    $payment->fill([
                        'status' => Payment::SUCCESS,
                        'paid_at' => $tx->paidAt ?? now(),
                        'net_amount' => $tx->netAmount,
                        'mdr_amount' => $tx->mdrAmount,
                        'handling_fee' => $tx->handlingFee,
                        'failure_reason' => null,
                    ])->save();

                    $this->settleDues($payment);
                    break;

                case TxStatus::FAILED:
                case TxStatus::CANCELLED:
                    if ($payment->isPending()) {
                        $payment->status = $tx->status;
                        $payment->failure_reason = 'Gateway status '.$tx->rawCode;
                    }
                    $payment->save();
                    break;

                default: // pending
                    $payment->save();
            }

            return true;
        });
    }

    /** Lunasi semua tagihan dalam pembayaran ini & hitung ulang status keanggotaan. */
    private function settleDues(Payment $payment): void
    {
        $payment->loadMissing('items.due', 'member');

        $alreadySettled = $payment->items->filter(fn ($item) => $item->due?->isSettled());
        if ($alreadySettled->isNotEmpty()) {
            // Mis. QR lama & QR baru sama-sama dibayar → uang masuk 2x untuk periode yang sama
            $labels = $alreadySettled->map(fn ($item) => $item->due->period_label)->implode(', ');
            $payment->forceFill(['failure_reason' => "Pembayaran ganda: periode {$labels} sudah lunas sebelumnya. Perlu refund manual."])->save();
            Log::critical('Pembayaran iuran ganda', ['ref' => $payment->merchant_ref, 'periods' => $labels]);
        }

        foreach ($payment->items as $item) {
            /** @var MemberDue $due */
            $due = $item->due;
            $due && $this->membership->markPaid($due, 'online', $payment->paid_at, null, 'QRIS '.$payment->merchant_ref);
        }

        // Pendaftar baru: iuran pertama lunas → anggota resmi (No. lambung, tgl bergabung, bisa login)
        if ($payment->member->isAwaitingPayment()) {
            $this->registrations->activate($payment->member);
            $payment->member->refresh();
        }

        $this->membership->refreshStatus($payment->member, null, null, 'Iuran lunas via QRIS '.$payment->merchant_ref);
    }
}
