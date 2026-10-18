<?php

use App\Models\Member;
use App\Models\MemberDue;
use App\Models\Payment;
use App\Models\PaymentNotification;
use App\Models\User;
use App\Payments\Data\TxStatus;
use App\Payments\Gateways\FakeGateway;
use App\Payments\PaymentService;
use App\Services\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const FEE = 150000;

beforeEach(function () {
    config(['yodu.driver' => 'fake', 'membership.annual_fee' => FEE, 'membership.grace_days' => 30]);
    $this->travelTo(Carbon::parse('2026-10-12 10:00:00'));
    $this->membership = app(MembershipService::class);
});

/** Member bergabung 1 Agt 2024, 2024-2025 lunas → 2025-2026 & 2026-2027 menunggak (tidak aktif). */
function memberWithArrears(): Member
{
    $member = Member::factory()->create(['tgl_bergabung' => '2024-08-01']);
    $svc = app(MembershipService::class);
    $svc->markPaid($svc->invoiceFor($member, $svc->termForYear($member, 2024)), 'transfer');
    $svc->refreshStatus($member);

    return $member->fresh();
}

function checkout(Member $member)
{
    Sanctum::actingAs($member, ['member']);

    return test()->postJson('/api/v1/member/payments/iuran');
}

function fakePay(string $ref, string $status = 'success'): void
{
    test()->postJson("/api/payments/fake/{$ref}/pay?status={$status}")->assertOk();
}

test('checkout creates a QRIS for every outstanding term and matches the dashboard total', function () {
    $member = memberWithArrears();
    Sanctum::actingAs($member, ['member']);

    $billing = $this->getJson('/api/v1/member/me')->assertOk()->json('data.billing');

    $res = checkout($member)->assertCreated();

    expect($billing['state'])->toBe('expired')
        ->and($billing['total'])->toBe(2 * FEE)
        ->and($res->json('data.amount'))->toBe($billing['total'])
        ->and($res->json('data.status'))->toBe('pending')
        ->and($res->json('data.qr_string'))->toStartWith('000201')
        ->and(collect($res->json('data.items'))->pluck('period_label')->all())->toBe(['2025-2026', '2026-2027'])
        ->and($res->json('data'))->not->toHaveKeys(['request_payload', 'response_payload', 'net_amount']);
});

test('checkout is idempotent while the QR is still valid', function () {
    $member = memberWithArrears();

    $first = checkout($member)->json('data.merchant_ref');
    $second = checkout($member)->json('data.merchant_ref');

    expect($second)->toBe($first)
        ->and(Payment::count())->toBe(1);
});

test('paying settles every due and reactivates the member', function () {
    $member = memberWithArrears();
    $ref = checkout($member)->json('data.merchant_ref');

    // Belum dibayar → masih pending
    $this->getJson("/api/v1/member/payments/{$ref}")->assertOk()->assertJsonPath('data.status', 'pending');

    fakePay($ref);
    $this->travel(6)->seconds(); // lewati throttle cek status 5 detik

    $res = $this->getJson("/api/v1/member/payments/{$ref}")->assertOk();

    $member->refresh();
    expect($res->json('data.status'))->toBe('success')
        ->and($res->json('data.qr_string'))->toBeNull()
        ->and($res->json('data.membership.status'))->toBe('active')
        ->and($member->status)->toBe(Member::STATUS_ACTIVE)
        ->and($member->paid_until->toDateString())->toBe('2027-07-31')
        ->and($member->dues()->where('status', MemberDue::UNPAID)->count())->toBe(0)
        ->and($member->dues()->where('payment_method', 'online')->count())->toBe(2)
        ->and(Payment::first()->net_amount)->toBe((int) round(2 * FEE * 0.993));

    // Kartu iuran di dasbor hilang
    $this->getJson('/api/v1/member/me')->assertJsonPath('data.billing.state', 'ok');
});

test('a member cannot see someone else payment', function () {
    $ref = checkout(memberWithArrears())->json('data.merchant_ref');

    Sanctum::actingAs(Member::factory()->create(), ['member']);

    $this->getJson("/api/v1/member/payments/{$ref}")->assertNotFound();
});

test('a forged success callback is rejected when the gateway status is not success', function () {
    $member = memberWithArrears();
    $ref = checkout($member)->json('data.merchant_ref');

    $this->postJson('/api/payments/yodu/callback', ['merchant_ref' => $ref, 'status' => 'success'])->assertOk();

    expect(Payment::first()->status)->toBe(Payment::PENDING)
        ->and($member->fresh()->status)->toBe(Member::STATUS_INACTIVE)
        ->and(PaymentNotification::first()->processed)->toBeFalse();
});

test('a confirmed callback is applied once even when delivered three times', function () {
    $member = memberWithArrears();
    $ref = checkout($member)->json('data.merchant_ref');
    Cache::put(FakeGateway::cacheKey($ref), 'success');

    foreach (range(1, 3) as $_) {
        $this->postJson('/api/payments/yodu/callback', ['merchant_ref' => $ref, 'status' => 'success'])->assertOk();
    }

    expect(Payment::first()->status)->toBe(Payment::SUCCESS)
        ->and($member->fresh()->status)->toBe(Member::STATUS_ACTIVE)
        ->and(PaymentNotification::count())->toBe(3)
        ->and($member->statusLogs()->where('reason', 'like', '%QRIS%')->count())->toBe(1);
});

test('an expired QR is replaced by a new one after a final status check', function () {
    $member = memberWithArrears();
    $old = checkout($member)->json('data.merchant_ref');

    $this->travel(20)->minutes();

    $this->getJson("/api/v1/member/payments/{$old}")->assertJsonPath('data.status', 'expired');

    $new = checkout($member)->assertCreated()->json('data.merchant_ref');
    expect($new)->not->toBe($old)
        ->and(Payment::pluck('status', 'merchant_ref')->all())->toBe([$old => 'expired', $new => 'pending']);
});

test('a payment made on the old QR right before expiry is still accepted', function () {
    $member = memberWithArrears();
    $ref = checkout($member)->json('data.merchant_ref');
    fakePay($ref);

    $this->travel(20)->minutes();
    $this->artisan('payments:reconcile')->assertSuccessful();

    expect(Payment::first()->status)->toBe(Payment::SUCCESS)
        ->and($member->fresh()->status)->toBe(Member::STATUS_ACTIVE);
});

test('an amount mismatch from the gateway does not settle the dues', function () {
    $member = memberWithArrears();
    $ref = checkout($member)->json('data.merchant_ref');

    app(PaymentService::class)->apply(new TxStatus($ref, 'success', payAmount: 1000));

    $payment = Payment::first();
    expect($payment->status)->toBe(Payment::PENDING)
        ->and($payment->failure_reason)->toContain('Nominal tidak cocok')
        ->and($member->fresh()->status)->toBe(Member::STATUS_INACTIVE);
});

test('a member without outstanding dues cannot check out', function () {
    $member = Member::factory()->create(['tgl_bergabung' => '2026-01-10']);
    $this->membership->markPaid($this->membership->invoiceFor($member, $this->membership->termForYear($member, 2026)), 'transfer');
    $this->membership->refreshStatus($member);

    checkout($member->fresh())->assertUnprocessable()->assertJsonPath('errors.payment.0', 'Tidak ada tagihan iuran saat ini.');
});

test('checkout is refused when the annual fee is not configured', function () {
    config(['membership.annual_fee' => 0]);

    checkout(memberWithArrears())->assertUnprocessable()->assertJsonPath('errors.payment.0', 'Nominal iuran belum diatur pengurus.');
});

test('payment endpoints require a member token', function () {
    $this->postJson('/api/v1/member/payments/iuran')->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/member/payments/iuran')->assertUnauthorized();
});
