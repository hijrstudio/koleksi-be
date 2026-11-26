<?php

namespace App\Payments;

use App\Models\Payment;
use App\Models\User;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Data\TxStatus;

/**
 * "Cek ke YODU" di laporan transaksi CMS: ambil status transaksi dari gateway (API cek status, hanya baca),
 * bandingkan dengan data kita, simpan hasilnya. Tidak mengubah status pembayaran.
 *
 * Sinkronkan (sync) hanya bila YODU menyatakan LUNAS tapi sistem kita belum: diproses lewat
 * PaymentService::apply (jalur resmi — nominal dicek, iuran dilunasi, idempoten).
 */
class PaymentReconciler
{
    public const MATCH = 'match';

    public const MISMATCH = 'mismatch';

    public const ERROR = 'error';

    public function __construct(private PaymentGateway $gateway, private PaymentService $payments) {}

    /** @return array{status: string, note: string, can_sync: bool, gateway_status: ?string} */
    public function check(Payment $payment, User $admin): array
    {
        $tx = $this->gateway->checkStatus($payment);
        [$status, $note, $canSync] = $this->compare($payment, $tx);

        $payment->forceFill([
            'reconciled_at' => now(),
            'reconcile_status' => $status,
            'reconcile_note' => mb_substr($note, 0, 255),
            'reconciled_by' => $admin->id,
        ])->save();

        return ['status' => $status, 'note' => $note, 'can_sync' => $canSync, 'gateway_status' => $tx?->status];
    }

    /** Terapkan status LUNAS dari YODU. @return bool true bila sekarang lunas di sistem kita */
    public function sync(Payment $payment, User $admin): bool
    {
        $tx = $this->gateway->checkStatus($payment);
        if ($tx?->status === TxStatus::SUCCESS && $payment->status !== Payment::SUCCESS) {
            $this->payments->apply($tx);
        }

        $payment->refresh();
        $this->check($payment, $admin);

        return $payment->status === Payment::SUCCESS;
    }

    /** @return array{0: string, 1: string, 2: bool} [status, catatan, bisa disinkronkan] */
    private function compare(Payment $payment, ?TxStatus $tx): array
    {
        if ($tx === null) {
            return [self::ERROR, 'YODU tidak bisa dihubungi / menolak permintaan. Coba lagi nanti.', false];
        }

        $rupiah = fn (?int $n) => $n === null ? '-' : 'Rp '.number_format($n, 0, ',', '.');
        $theirs = strtoupper($tx->status).($tx->payAmount !== null ? ' · '.$rupiah($tx->payAmount) : '').($tx->channel ? ' · '.$tx->channel : '');
        $oursPaid = $payment->status === Payment::SUCCESS;
        $theirsPaid = $tx->status === TxStatus::SUCCESS;

        if ($oursPaid && $theirsPaid) {
            return $tx->payAmount !== null && $tx->payAmount !== $payment->amount
                ? [self::MISMATCH, "Nominal berbeda. YODU: {$theirs}; sistem: {$rupiah($payment->amount)}", false]
                : [self::MATCH, "Cocok. YODU: {$theirs}", false];
        }
        if ($theirsPaid) {
            return [self::MISMATCH, "YODU sudah LUNAS ({$theirs}), sistem masih ".strtoupper($payment->status).'.', true];
        }
        if ($oursPaid) {
            return [self::MISMATCH, "Sistem LUNAS, YODU: {$theirs}.", false];
        }

        return [self::MATCH, "Sama-sama belum lunas. YODU: {$theirs}", false];
    }
}
