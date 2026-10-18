<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Payment;
use App\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint FE untuk tombol "Bayar Iuran" / "Daftar Ulang" di kartu iuran dasbor.
 * Sengaja TIDAK dibatasi member aktif — member nonaktif justru yang perlu bayar.
 */
class IuranPaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    /** POST /api/v1/member/payments/iuran — buat / ambil QRIS aktif. */
    public function store(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->user();

        $payment = $this->payments->checkout($member);

        return response()->json(['data' => $payment->toCheckoutArray()], 201);
    }

    /** GET /api/v1/member/payments/{merchantRef} — polling status (hanya milik member sendiri). */
    public function show(Request $request, string $merchantRef): JsonResponse
    {
        /** @var Member $member */
        $member = $request->user();

        $payment = Payment::where('merchant_ref', $merchantRef)
            ->where('member_id', $member->id)
            ->firstOrFail();

        $payment = $this->payments->refresh($payment)->load('dues');
        $member->refresh();

        return response()->json([
            'data' => $payment->toCheckoutArray() + [
                'membership' => [
                    'status' => $member->status,
                    'active_until' => $member->active_until?->toDateString(),
                    'paid_until' => $member->paid_until?->toDateString(),
                ],
            ],
        ]);
    }
}
