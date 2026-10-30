<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Payment;
use App\Payments\BillingResolver;
use App\Payments\PaymentService;
use App\Services\RegistrationPaymentToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Halaman "Bayar iuran pendaftaran" (FE /daftar/bayar) — dipanggil server Next.js (FRONTEND_API_TOKEN).
 * Pendaftar belum bisa login; aksesnya lewat token dari link e-mail (RegistrationPaymentToken), yang hanya
 * membuka pembayaran iuran pertama milik pendaftar itu.
 *
 *   POST /api/v1/registrations/payment          {token}               ringkasan (nama, nominal, batas, QR aktif)
 *   POST /api/v1/registrations/payment/checkout {token}               buat / ambil QRIS
 *   POST /api/v1/registrations/payment/status   {token, merchant_ref} polling status
 *
 * Token dikirim di body (bukan URL) supaya tidak tercatat di log akses. Setelah lunas, ringkasan & status
 * menampilkan No. lambung — data pribadi lain tidak pernah dikirim.
 */
class RegistrationPaymentController extends Controller
{
    public function __construct(private PaymentService $payments, private BillingResolver $billing) {}

    public function show(Request $request): JsonResponse
    {
        $member = $this->member($request);

        if ($member->isAwaitingPayment()) {
            $dues = $this->billing->payableDues($member);
            $open = $member->payments()->pending()->where('expired_at', '>', now())->with('dues')->latest('id')->first();
        }

        return response()->json(['data' => $this->summary($member) + [
            'amount' => isset($dues) ? (int) $dues->sum('amount') : null,
            'periods' => isset($dues) ? $dues->pluck('period_label')->values() : [],
            'payment' => isset($open) ? $open->toCheckoutArray() : null,
        ]]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $member = $this->member($request);
        abort_unless($member->isAwaitingPayment(), 409, 'Pendaftaran ini sudah dibayar. Silakan masuk.');

        return response()->json(['data' => $this->payments->checkout($member)->toCheckoutArray()], 201);
    }

    public function status(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $merchantRef = (string) $request->validate(['merchant_ref' => ['required', 'string', 'max:64']])['merchant_ref'];

        $payment = Payment::where('merchant_ref', $merchantRef)->where('member_id', $member->id)->firstOrFail();
        $payment = $this->payments->refresh($payment)->load('dues');

        return response()->json(['data' => $payment->toCheckoutArray() + ['registration' => $this->summary($member->refresh())]]);
    }

    private function member(Request $request): Member
    {
        $token = $request->validate(['token' => ['required', 'string', 'max:200']])['token'];
        $member = RegistrationPaymentToken::member($token);

        // Token rusak / kedaluwarsa, atau pendaftaran sudah dihapus / bukan pendaftaran website
        abort_if(! $member || ! in_array($member->registration_status, [Member::REG_AWAITING_PAYMENT, Member::REG_APPROVED], true),
            404, 'Link pembayaran tidak valid atau sudah kedaluwarsa.');

        return $member;
    }

    /** @return array<string, mixed> */
    private function summary(Member $member): array
    {
        return [
            'status' => $member->isAwaitingPayment() ? 'awaiting_payment' : 'active',
            'name' => $member->nama_panggilan ?: $member->nama_lengkap,
            'payment_due_at' => $member->payment_due_at?->toIso8601String(),
            'no_lambung' => $member->isAwaitingPayment() ? null : $member->noLambungLabel(),
        ];
    }
}
