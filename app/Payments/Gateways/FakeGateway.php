<?php

namespace App\Payments\Gateways;

use App\Models\Payment;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Data\QrResult;
use App\Payments\Data\TxStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Simulasi lokal (PAYMENT_DRIVER=fake) — tidak menghubungi YODU. Ditolak di production (AppServiceProvider).
 * Bayar pura-pura: POST /api/payments/fake/{merchant_ref}/pay?status=success|failed
 * Status dibaca lewat checkStatus() → jalur polling FE & konfirmasi callback ikut teruji.
 */
class FakeGateway implements PaymentGateway
{
    public function __construct(private array $config = []) {}

    public static function cacheKey(string $merchantRef): string
    {
        return "fake-pay:{$merchantRef}";
    }

    public function name(): string
    {
        return 'fake';
    }

    public function createQr(Payment $payment): QrResult
    {
        return new QrResult(
            qrString: '00020101021226FAKEQRIS'.$payment->merchant_ref.'5303360540'.$payment->amount.'6304FAKE',
            expiredAt: now()->addSeconds($this->config['qr_expiry_seconds'] ?? 900),
            gatewayRef: 'FAKE-'.$payment->id,
            gatewayQrId: 'FAKEQR-'.$payment->id,
        );
    }

    public function cancelQr(Payment $payment, string $reason = 'Diganti QR baru'): bool
    {
        Cache::forget(self::cacheKey($payment->merchant_ref));

        return true;
    }

    public function checkStatus(Payment $payment): ?TxStatus
    {
        $status = Cache::get(self::cacheKey($payment->merchant_ref), TxStatus::PENDING);
        $paid = $status === TxStatus::SUCCESS;

        return new TxStatus(
            merchantRef: $payment->merchant_ref,
            status: $status,
            payAmount: $payment->amount,
            netAmount: $paid ? (int) round($payment->amount * 0.993) : null,
            mdrAmount: $paid ? (int) round($payment->amount * 0.007) : null,
            handlingFee: $paid ? 0 : null,
            gatewayRef: $payment->gateway_ref,
            channel: $paid ? 'FAKE WALLET' : null,
            paidAt: $paid ? now() : null,
            rawCode: $status,
        );
    }

    public function verifyCallback(Request $request): bool
    {
        return ! app()->isProduction();
    }

    public function parseCallback(Request $request): TxStatus
    {
        return new TxStatus(
            merchantRef: (string) $request->input('merchant_ref'),
            status: (string) $request->input('status', TxStatus::SUCCESS),
            raw: $request->all(),
            fromCallback: true,
        );
    }

    public function callbackResponse(bool $ok, array $payload = []): Response
    {
        return response()->json(['ok' => $ok]);
    }
}
