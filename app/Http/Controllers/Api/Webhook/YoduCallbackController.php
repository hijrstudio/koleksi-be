<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Controller;
use App\Models\PaymentNotification;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * POST /api/payments/yodu/callback
 * Daftarkan URL ini di OACP → Configuration → Access API → "Callback ACQ MPM URL".
 *
 * YODU hanya mencoba 3x (jeda 5 detik) → jawab SECEPATNYA.
 * Proses (konfirmasi ke API cek status + update status) dijalankan SETELAH respons terkirim.
 * Callback tidak pernah dipercaya begitu saja: SUCCESS selalu dikonfirmasi ke API cek status (PaymentService::apply).
 */
class YoduCallbackController extends Controller
{
    private const MAX_BODY_BYTES = 65536;

    public function __invoke(Request $request, PaymentGateway $gateway): Response
    {
        $body = $request->getContent();
        $payload = $request->isJson() ? $request->json()->all() : [];

        $log = PaymentNotification::create([
            'gateway' => $gateway->name(),
            'merchant_ref' => Str::limit((string) ($payload['originalPartnerReferenceNo'] ?? $payload['merchant_ref'] ?? ''), 64, '') ?: null,
            'gateway_status' => Str::limit((string) ($payload['latestTransactionStatus'] ?? $payload['status'] ?? ''), 10, '') ?: null,
            'ip' => $request->ip(),
            'headers' => collect($request->headers->all())
                ->except(['cookie', 'authorization', 'x-client-key']) // x-client-key = client secret kita
                ->map(fn ($v) => Str::limit((string) ($v[0] ?? ''), 500))->all(),
            'body' => Str::limit($body, self::MAX_BODY_BYTES, ''),
        ]);

        if (strlen($body) > self::MAX_BODY_BYTES || $payload === [] || ! $gateway->verifyCallback($request)) {
            $log->update(['error' => 'Ditolak verifikasi awal (IP / format)']);

            return $gateway->callbackResponse(false, $payload);
        }

        $tx = $gateway->parseCallback($request);
        $log->update(['accepted' => true, 'merchant_ref' => Str::limit($tx->merchantRef, 64, '') ?: $log->merchant_ref]);

        dispatch(function () use ($tx, $log) {
            try {
                $log->update(['processed' => app(PaymentService::class)->apply($tx)]);
            } catch (Throwable $e) {
                Log::error('Proses callback pembayaran gagal', ['id' => $log->id, 'error' => $e->getMessage()]);
                $log->update(['error' => Str::limit($e->getMessage(), 1000)]);
            }
        })->afterResponse();

        return $gateway->callbackResponse(true, $payload);
    }
}
