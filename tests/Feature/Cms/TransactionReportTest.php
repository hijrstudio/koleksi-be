<?php

use App\Models\Member;
use App\Models\MemberDue;
use App\Models\Payment;
use App\Models\User;
use App\Payments\Data\TxStatus;
use App\Payments\Gateways\FakeGateway;
use App\Services\TransactionReport;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config(['yodu.driver' => 'fake']);
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Bendahara']);
    $this->admin->assignRole('super-admin');
    $this->budi = Member::factory()->create(['no_lambung' => 7, 'nama_lengkap' => 'Budi Santoso']);
    $this->sari = Member::factory()->create(['no_lambung' => 8, 'nama_lengkap' => 'Sari Dewi']);
});

function txDue(Member $member, array $attrs): MemberDue
{
    static $year = 2020;
    $year++;

    return MemberDue::create($attrs + [
        'invoice_number' => "IUR-{$year}-".$member->no_lambung,
        'member_id' => $member->id,
        'period_label' => "{$year}-".($year + 1),
        'period_start' => "{$year}-01-01",
        'period_end' => ($year + 1).'-12-31',
        'amount' => 300000,
        'status' => MemberDue::PAID,
        'paid_at' => '2026-11-20 03:00:00',
    ]);
}

function txPayment(Member $member, array $attrs = []): Payment
{
    static $n = 0;
    $n++;

    return Payment::create($attrs + [
        'member_id' => $member->id,
        'gateway' => 'fake',
        'merchant_ref' => "KLK-TEST-{$n}",
        'gateway_ref' => "YODU-REF-{$n}",
        'amount' => 300000,
        'net_amount' => 297900,
        'mdr_amount' => 2100,
        'status' => Payment::SUCCESS,
        'payment_channel' => 'GoPay',
        'paid_at' => '2026-11-21 03:00:00',
    ]);
}

test('the report lists QRIS payments and dues recorded by an admin, marked apart, without imported data', function () {
    txPayment($this->budi);
    txPayment($this->sari, ['status' => Payment::EXPIRED, 'paid_at' => null]);
    txDue($this->sari, ['payment_method' => 'cash', 'recorded_by' => $this->admin->id, 'amount' => 500000]);
    txPayment($this->sari, ['purpose' => Payment::PURPOSE_REGISTRATION, 'amount' => 500000, 'merchant_ref' => 'KLKR00099-REG']);
    txDue($this->budi, ['payment_method' => 'transfer', 'recorded_by' => null, 'invoice_number' => 'IUR-IMPORT-7']);   // import Excel: tidak tampil
    txDue($this->budi, ['payment_method' => 'online']);                                        // sudah ada sebagai baris QRIS
    txDue($this->budi, ['payment_method' => 'waived', 'status' => MemberDue::WAIVED, 'amount' => 0]);   // bukan transaksi uang

    $this->actingAs($this->admin)->get(route('transactions.index'))->assertOk()
        ->assertSee('KLK-TEST-1')
        ->assertDontSee('KLK-TEST-2')            // default: hanya yang lunas
        ->assertSee('Dicatat admin')
        ->assertSee('Bendahara')
        ->assertDontSee('IUR-IMPORT-7')
        ->assertSee('QRIS Pendaftaran')
        ->assertSee('QRIS Iuran')
        ->assertSee('Rp 1.300.000')              // total lunas: 300rb QRIS iuran + 500rb QRIS pendaftaran + 500rb admin
        ->assertSee('Rp 4.200');                 // MDR 2 transaksi QRIS

    $this->actingAs($this->admin)->get(route('transactions.index', ['source' => 'admin']))->assertOk()
        ->assertSee('Sari Dewi')->assertDontSee('KLK-TEST-1')->assertDontSee('KLKR00099-REG');
    $this->actingAs($this->admin)->get(route('transactions.index', ['source' => 'registration']))->assertOk()
        ->assertSee('KLKR00099-REG')->assertDontSee('KLK-TEST-1')->assertDontSee('IUR-');
    $this->actingAs($this->admin)->get(route('transactions.index', ['source' => 'dues']))->assertOk()
        ->assertSee('KLK-TEST-1')->assertDontSee('KLKR00099-REG');

    $summary = app(TransactionReport::class)->summary(['status' => 'success']);
    expect($summary['by_source'])->toBe([
        'registration' => ['count' => 1, 'gross' => 500000],
        'dues' => ['count' => 1, 'gross' => 300000],
        'admin' => ['count' => 1, 'gross' => 500000],
    ]);
    $this->actingAs($this->admin)->get(route('transactions.index', ['status' => 'expired']))->assertOk()
        ->assertSee('KLK-TEST-2')->assertDontSee('IUR-');   // iuran admin selalu lunas → tidak ikut
    $this->actingAs($this->admin)->get(route('transactions.index', ['search' => '#8']))->assertOk()
        ->assertSee('Sari Dewi')->assertDontSee('KLK-TEST-1');
    $this->actingAs($this->admin)->get(route('transactions.index', ['search' => 'YODU-REF-1']))->assertOk()
        ->assertSee('KLK-TEST-1');
});

test('the date filter uses WIB days', function () {
    txPayment($this->budi, ['paid_at' => '2026-11-21 18:00:00']);   // 22 Nov 01:00 WIB

    $this->actingAs($this->admin)->get(route('transactions.index', ['from' => '2026-11-22', 'to' => '2026-11-22']))->assertSee('KLK-TEST-');
    $this->actingAs($this->admin)->get(route('transactions.index', ['from' => '2026-11-21', 'to' => '2026-11-21']))->assertDontSee('KLK-TEST-');
    $this->actingAs($this->admin)->get(route('transactions.index', ['from' => '2026-11-23', 'to' => '2026-11-22']))->assertSessionHasErrors('to');
});

test('checking with YODU stores whether our status and amount match', function () {
    $paid = txPayment($this->budi);
    Cache::put(FakeGateway::cacheKey($paid->merchant_ref), TxStatus::SUCCESS);

    $this->actingAs($this->admin)->postJson(route('transactions.reconcile', $paid))->assertOk()
        ->assertJsonPath('status', 'match')->assertJsonPath('can_sync', false);
    expect($paid->fresh()->reconcile_status)->toBe('match')->and($paid->fresh()->reconciled_by)->toBe($this->admin->id);

    // Sistem lunas, YODU belum → tidak cocok
    Cache::put(FakeGateway::cacheKey($paid->merchant_ref), TxStatus::PENDING);
    $this->actingAs($this->admin)->postJson(route('transactions.reconcile', $paid))->assertJsonPath('status', 'mismatch');
});

test('a payment YODU reports paid but we marked expired can be synced, settling the dues', function () {
    $due = txDue($this->budi, ['status' => MemberDue::UNPAID, 'payment_method' => null, 'paid_at' => null]);
    $payment = txPayment($this->budi, ['status' => Payment::EXPIRED, 'paid_at' => null, 'net_amount' => null, 'mdr_amount' => null]);
    $payment->items()->create(['member_due_id' => $due->id, 'amount' => 300000]);
    Cache::put(FakeGateway::cacheKey($payment->merchant_ref), TxStatus::SUCCESS);

    $this->actingAs($this->admin)->postJson(route('transactions.reconcile', $payment))
        ->assertJsonPath('status', 'mismatch')->assertJsonPath('can_sync', true);

    $this->actingAs($this->admin)->postJson(route('transactions.sync', $payment))->assertOk()->assertJsonPath('paid', true);
    expect($payment->fresh()->status)->toBe(Payment::SUCCESS)
        ->and($payment->fresh()->reconcile_status)->toBe('match')
        ->and($due->fresh()->status)->toBe(MemberDue::PAID)
        ->and($due->fresh()->payment_method)->toBe('online');

    // Belum lunas di YODU → sinkron ditolak, tidak ada yang berubah
    $other = txPayment($this->sari, ['status' => Payment::EXPIRED, 'paid_at' => null]);
    $this->actingAs($this->admin)->postJson(route('transactions.sync', $other))->assertStatus(422);
    expect($other->fresh()->status)->toBe(Payment::EXPIRED);
});

test('the CSV export follows the filters and neutralises spreadsheet formulas', function () {
    txPayment($this->budi);
    $this->budi->forceFill(['nama_lengkap' => '=HYPERLINK("http://evil")'])->save();

    $csv = $this->actingAs($this->admin)->get(route('transactions.export'))->assertOk()->streamedContent();

    expect($csv)->toContain('KLK-TEST-')->toContain("'=HYPERLINK")->toContain('Tanggal (WIB)');
});

test('admins without the transactions permission cannot see or check transactions', function () {
    $editor = User::factory()->create();
    $payment = txPayment($this->budi);

    $this->actingAs($editor)->get(route('transactions.index'))->assertForbidden();
    $this->actingAs($editor)->get(route('transactions.export'))->assertForbidden();
    $this->actingAs($editor)->postJson(route('transactions.reconcile', $payment))->assertForbidden();
    $this->actingAs($editor)->postJson(route('transactions.sync', $payment))->assertForbidden();
});
