<?php

use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const UJI_BASE = 'https://sandbox-yodupay-api.yodu.test';

beforeEach(function () {
    Storage::fake('local');
    config(['yodu' => array_merge(config('yodu'), [
        'driver' => 'yodu',
        'base_url' => UJI_BASE,
        'client_id' => 'CLIENT-ID',
        'client_secret' => 'CLIENT-SECRET',
        'private_key' => null,
        'private_key_path' => 'tests/Fixtures/yodu-test-private.pem',
        'channel_id' => '95221',
        'merchant_id' => 'M001',
        'store_id' => 'S001',
        'terminal_id' => 'T001',
    ])]);
    Cache::flush();
});

function ujiReport(): string
{
    $files = Storage::disk('local')->files('yodu-uji-fungsional');
    expect($files)->toHaveCount(1);

    return Storage::disk('local')->get($files[0]);
}

test('refuses to run against a non-sandbox base url', function () {
    config(['yodu.base_url' => 'https://yodupay-api.yodu.id']);
    Http::fake();

    $this->artisan('yodu:uji-fungsional')->assertFailed();

    Http::assertNothingSent();
});

test('runs the generate QR scenarios and writes a copy-ready report', function () {
    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/access/token')) {
            return Http::response(['responseCode' => '2007300', 'accessToken' => 'GOOD-TOKEN']);
        }

        $body = json_decode($request->body(), true);

        return match (true) {
            $request->header('Authorization')[0] !== 'Bearer GOOD-TOKEN' => Http::response(['responseCode' => '4014701', 'responseMessage' => 'Access Token Invalid'], 401),
            ! isset($body['merchantId']) => Http::response(['responseCode' => '4004702', 'responseMessage' => 'Invalid Mandatory Field {merchantId}'], 400),
            $body['amount']['value'] === 'abc' => Http::response(['responseCode' => '4004701', 'responseMessage' => 'Invalid Field Format {amount.value}'], 400),
            $body['merchantId'] === '999999999' => Http::response(['responseCode' => '4044708', 'responseMessage' => 'Invalid Merchant'], 404),
            default => Http::response(['responseCode' => '2008000', 'responseMessage' => 'Successful', 'responseData' => ['qrContent' => 'QRIS']]),
        };
    });

    $this->artisan('yodu:uji-fungsional', ['--case' => ['18.1', '18.3', '18.4', '18.6', '18.7']])->assertSuccessful();

    $report = ujiReport();
    expect($report)
        ->toContain('## 18.1 Any Service: Access Token Invalid')
        ->toContain('POST '.UJI_BASE.'/openapi/v1.0/qr-acq/mpm/generate')
        ->toContain('"responseCode": "4014701"')
        ->toContain('"responseCode": "4044708"')
        ->toContain('Hasil: SESUAI (responseCode 2008000)')
        ->toContain('- tanpa partnerReferenceNo → 2008000 Successful')   // field opsional di sandbox: lanjut ke field berikut
        ->toContain('- tanpa merchantId → 4004702 Invalid Mandatory Field {merchantId}')
        ->not->toContain('- tanpa amount')                               // berhenti di percobaan pertama yang sesuai
        ->not->toContain('TIDAK SESUAI')
        ->not->toContain('## 18.2');
});

test('the same X-EXTERNAL-ID is sent twice for 18.5', function () {
    Http::fake([
        UJI_BASE.'/openapi/v1.0/access/token' => Http::response(['responseCode' => '2007300', 'accessToken' => 'GOOD-TOKEN']),
        UJI_BASE.'/openapi/v1.0/qr-acq/mpm/generate' => Http::sequence()
            ->push(['responseCode' => '2008000', 'responseMessage' => 'Successful'])
            ->push(['responseCode' => '4094700', 'responseMessage' => 'Conflict'], 409),
    ]);

    $this->artisan('yodu:uji-fungsional', ['--case' => ['18.5']])->assertSuccessful();

    $ids = Http::recorded()->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => str_ends_with($r->url(), '/generate'))
        ->map(fn (Request $r) => $r->header('X-EXTERNAL-ID')[0])->values();

    expect($ids)->toHaveCount(2)->and($ids[0])->toBe($ids[1])
        ->and(ujiReport())->toContain('Hasil: SESUAI (responseCode 4094700)');
});

test('query payment cases check a paid transaction and an unknown reference', function () {
    Payment::create([
        'member_id' => Member::factory()->create()->id, 'gateway' => 'yodu', 'merchant_ref' => 'KLK0001-PAID',
        'amount' => 10000, 'status' => Payment::SUCCESS, 'gateway_ref' => 'YODU-REF-1',
    ]);
    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/access/token')) {
            return Http::response(['responseCode' => '2007300', 'accessToken' => 'GOOD-TOKEN']);
        }

        if (str_ends_with($request->url(), '/generate')) {
            return Http::response(['responseCode' => '2008000', 'responseMessage' => 'Successful', 'referenceNo' => 'ORD-UNPAID-1']);
        }

        return json_decode($request->body(), true)['originalPartnerReferenceNo'] === 'KLK0001-PAID'
            ? Http::response(['responseCode' => '2008200', 'responseMessage' => 'Approved', 'latestTransactionStatus' => '00'])
            : Http::response(['responseCode' => '4045101', 'responseMessage' => 'Transaction Not Found'], 404);
    });

    $this->artisan('yodu:uji-fungsional', ['--case' => ['18.15', '18.17']])->assertSuccessful();

    expect(ujiReport())
        ->toContain('## 18.15 Query Payment: Successful Transaction (00)')
        ->toContain('Transaksi KLK0001-PAID, latestTransactionStatus: 00.')
        ->toContain('## 18.17 Query Payment: Transaction Not Found')
        ->toContain('Hasil: SESUAI (responseCode 4045101)')
        ->toContain('referenceNo ORD-UNPAID-1 (dari respons Generate QR, 2008000)')
        ->not->toContain('TIDAK SESUAI');

    // 18.17 mengecek referenceNo dari Generate QR yang belum dibayar, dengan partnerReferenceNo QR yang sama
    $generated = Http::recorded()->map(fn ($pair) => $pair[0])->first(fn (Request $r) => str_ends_with($r->url(), '/generate'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/paystatus')
        && json_decode($r->body(), true)['originalReferenceNo'] === 'ORD-UNPAID-1'
        && json_decode($r->body(), true)['originalPartnerReferenceNo'] === json_decode($generated->body(), true)['partnerReferenceNo']);

    expect(Payment::count())->toBe(1); // QR uji 18.17 tidak disimpan
});

test('a callback logged before replies were recorded is skipped, not failed', function () {
    PaymentNotification::create([
        'gateway' => 'yodu', 'gateway_status' => '00', 'accepted' => true,
        'headers' => [], 'body' => '{"latestTransactionStatus":"00"}',
    ]);
    Http::fake();

    $this->artisan('yodu:uji-fungsional', ['--case' => ['18.18']])->assertSuccessful();

    expect(ujiReport())->toContain('Hasil: DILEWATI')->toContain('masuk sebelum respons kita dicatat');
});

test('callback and status cases use logged callbacks and the last paid transaction', function () {
    $member = Member::factory()->create();
    Payment::create([
        'member_id' => $member->id, 'gateway' => 'yodu', 'merchant_ref' => 'KLK0001-PAID',
        'amount' => 10000, 'status' => Payment::SUCCESS, 'gateway_ref' => 'YODU-REF-1',
    ]);
    PaymentNotification::create([
        'gateway' => 'yodu', 'gateway_status' => '00', 'accepted' => true,
        'headers' => ['x-timestamp' => '2026-10-28T10:00:00+07:00'], 'body' => '{"latestTransactionStatus":"00"}',
        'response_status' => 200, 'response' => '{"responseCode":"2005200","responseMessage":"Successful"}',
    ]);
    Http::fake([
        UJI_BASE.'/openapi/v1.0/access/token' => Http::response(['responseCode' => '2007300', 'accessToken' => 'GOOD-TOKEN']),
        UJI_BASE.'/openapi/v1.0/qr-acq/paystatus' => Http::response(['responseCode' => '2005300', 'responseMessage' => 'Successful', 'latestTransactionStatus' => '00']),
    ]);

    $this->artisan('yodu:uji-fungsional', ['--case' => ['18.18', '18.19', '18.24']])->assertSuccessful();

    expect(ujiReport())
        ->toContain('## 18.18 Payment Notification: Successful Transaction (00)')
        ->toContain('Hasil: SESUAI (responseCode 2005200)')
        ->toContain('"latestTransactionStatus": "00"')
        ->toContain('Hasil: DILEWATI')                    // 18.19: belum ada callback "06"
        ->toContain('Transaksi KLK0001-PAID, latestTransactionStatus: 00.')
        ->toContain('Hasil: SESUAI (responseCode 2005300)');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/paystatus')
        && json_decode($r->body(), true)['originalPartnerReferenceNo'] === 'KLK0001-PAID');
});
