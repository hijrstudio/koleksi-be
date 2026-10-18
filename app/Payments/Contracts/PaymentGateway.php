<?php

namespace App\Payments\Contracts;

use App\Models\Payment;
use App\Payments\Data\QrResult;
use App\Payments\Data\TxStatus;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface PaymentGateway
{
    public function name(): string;

    /** Buat QRIS Dynamic untuk $payment (sudah tersimpan, punya merchant_ref & amount). */
    public function createQr(Payment $payment): QrResult;

    /** Batalkan QR yang belum dibayar. */
    public function cancelQr(Payment $payment, string $reason = 'Diganti QR baru'): bool;

    /** Tanya status transaksi ke gateway. Null = gateway tidak bisa dihubungi / jawaban tidak dikenali. */
    public function checkStatus(Payment $payment): ?TxStatus;

    /** Verifikasi awal callback (whitelist IP / signature). */
    public function verifyCallback(Request $request): bool;

    public function parseCallback(Request $request): TxStatus;

    /** Body respons yang diminta gateway untuk callback. */
    public function callbackResponse(bool $ok, array $payload = []): Response;
}
