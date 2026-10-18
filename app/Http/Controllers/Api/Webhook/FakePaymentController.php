<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Payments\Gateways\FakeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * POST /api/payments/fake/{merchantRef}/pay?status=success|failed
 * Simulasi "member sudah scan & bayar" untuk PAYMENT_DRIVER=fake. Route ini tidak didaftarkan di production.
 */
class FakePaymentController extends Controller
{
    public function __invoke(Request $request, string $merchantRef): JsonResponse
    {
        abort_if(app()->isProduction() || config('yodu.driver') !== 'fake', 404);

        $payment = Payment::where('gateway', 'fake')->where('merchant_ref', $merchantRef)->firstOrFail();
        $status = $request->query('status') === 'failed' ? 'failed' : 'success';

        Cache::put(FakeGateway::cacheKey($payment->merchant_ref), $status, now()->addHour());

        return response()->json(['message' => "Simulasi {$status}. FE akan mendeteksi lewat polling."]);
    }
}
