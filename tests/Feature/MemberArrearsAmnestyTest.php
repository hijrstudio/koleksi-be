<?php

use App\Models\Member;
use App\Models\MemberDue;
use App\Models\Payment;
use App\Payments\BillingResolver;
use App\Services\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const AMNESTY_FEE = 150000;

beforeEach(function () {
    config([
        'yodu.driver' => 'fake',
        'membership.annual_fee' => AMNESTY_FEE,
        'membership.grace_days' => 30,
        'membership.arrears_cutoff_year' => 2026,
    ]);
    $this->travelTo(Carbon::parse('2026-10-12 10:00:00'));
    $this->membership = app(MembershipService::class);
});

/** Bergabung 1 Agt 2022; hanya 2022-2023 lunas → 2023-2024 s/d 2026-2027 menunggak (seperti data import). */
function memberWithOldArrears(string $joined = '2022-08-01'): Member
{
    $member = Member::factory()->create(['tgl_bergabung' => $joined]);
    $svc = app(MembershipService::class);
    $svc->markPaid($svc->invoiceFor($member, $svc->termForYear($member, 2022)), 'transfer');
    $svc->invoiceFor($member, $svc->termForYear($member, 2023));     // sel merah di Excel
    $svc->invoiceFor($member, $svc->termForYear($member, 2026));
    // 2024-2025 & 2025-2026 sengaja tanpa tagihan (sel kosong di Excel)
    $svc->refreshStatus($member);

    return $member->fresh();
}

test('billing only charges the cutoff period, even before the amnesty is recorded', function () {
    $member = memberWithOldArrears();

    $billing = app(BillingResolver::class)->summary($member);

    expect($billing['state'])->toBe('expired')
        ->and(array_column($billing['items'], 'period_label'))->toBe(['2026-2027'])
        ->and($billing['total'])->toBe(AMNESTY_FEE);

    Sanctum::actingAs($member, ['member']);
    $this->postJson('/api/v1/member/payments/iuran')
        ->assertCreated()
        ->assertJsonPath('data.amount', AMNESTY_FEE);
});

test('without a cutoff every arrear is charged again', function () {
    config(['membership.arrears_cutoff_year' => null]);

    $items = app(BillingResolver::class)->summary(memberWithOldArrears())['items'];

    expect(array_column($items, 'period_label'))->toBe(['2023-2024', '2024-2025', '2025-2026', '2026-2027']);
});

test('waiving arrears records every unsettled earlier period and leaves the rest untouched', function () {
    $member = memberWithOldArrears();

    expect($this->membership->waiveArrears($member, 2026))->toBe(3);

    $dues = $member->dues()->orderBy('period_start')->get()->keyBy('period_label');
    expect($dues['2022-2023']->status)->toBe(MemberDue::PAID)
        ->and($dues['2023-2024']->isAmnesty())->toBeTrue()
        ->and($dues['2024-2025']->isAmnesty())->toBeTrue()      // dibuat untuk periode tanpa tagihan
        ->and($dues['2025-2026']->isAmnesty())->toBeTrue()
        ->and($dues['2025-2026']->amount)->toBe(0)
        ->and($dues['2025-2026']->notes)->toBe('Pemutihan tunggakan sebelum periode 2026-2027')
        ->and($dues['2026-2027']->status)->toBe(MemberDue::UNPAID);

    $member->refresh();
    expect($member->paid_until->toDateString())->toBe('2026-07-31')
        ->and(array_column(app(BillingResolver::class)->summary($member)['items'], 'period_label'))->toBe(['2026-2027']);

    // Dijalankan ulang → tidak ada yang berubah
    expect($this->membership->waiveArrears($member, 2026))->toBe(0);
});

test('a waived current term reactivates the member until their cutoff period starts', function () {
    // Ulang tahun keanggotaan Desember → hari ini (Okt 2026) masih periode 2025-2026
    $member = memberWithOldArrears('2022-12-01');
    expect($member->status)->toBe(Member::STATUS_INACTIVE);

    $this->membership->waiveArrears($member, 2026);
    $member->refresh();

    expect($member->status)->toBe(Member::STATUS_ACTIVE)
        ->and($member->paid_until->toDateString())->toBe('2026-11-30')
        // Daftar ulang 1 Des 2026 masih > 30 hari lagi → belum ditagih
        ->and(app(BillingResolver::class)->summary($member)['state'])->toBe('ok');

    $this->travelTo(Carbon::parse('2026-11-10'));
    $billing = app(BillingResolver::class)->summary($member);
    expect($billing['state'])->toBe('due_soon')
        ->and(array_column($billing['items'], 'period_label'))->toBe(['2026-2027']);
});

test('members who joined from the cutoff year onwards are not affected', function () {
    $member = Member::factory()->create(['tgl_bergabung' => '2026-03-01']);

    expect($this->membership->waiveArrears($member, 2026))->toBe(0)
        ->and($member->dues()->count())->toBe(0);
});

test('the command waives arrears, supports dry runs and skips members with a pending QRIS', function () {
    $member = memberWithOldArrears();
    $busy = memberWithOldArrears('2022-09-01');
    Payment::create([
        'member_id' => $busy->id, 'gateway' => 'fake', 'merchant_ref' => 'KLK-TEST-PENDING',
        'amount' => 4 * AMNESTY_FEE, 'status' => Payment::PENDING,
    ]);

    $this->artisan('members:waive-arrears', ['--dry-run' => true])->assertSuccessful();
    expect($member->dues()->where('payment_method', MemberDue::METHOD_AMNESTY)->count())->toBe(0);

    $this->artisan('members:waive-arrears')
        ->expectsOutputToContain('Dilewati karena sedang punya QRIS pending')
        ->assertSuccessful();

    expect($member->dues()->where('payment_method', MemberDue::METHOD_AMNESTY)->count())->toBe(3)
        ->and($busy->dues()->where('payment_method', MemberDue::METHOD_AMNESTY)->count())->toBe(0);

    $this->artisan('members:waive-arrears', ['--before' => '1999'])->assertFailed();
});
