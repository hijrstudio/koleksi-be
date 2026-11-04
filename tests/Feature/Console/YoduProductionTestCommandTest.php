<?php

use App\Models\PaymentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const PROD_BASE = 'https://yodupay-api.yodu.test';

beforeEach(function () {
    Storage::fake('local');
    config(['yodu' => array_merge(config('yodu'), [
        'driver' => 'yodu',
        'base_url' => PROD_BASE,
        'client_id' => 'CLIENT-ID',
        'client_secret' => 'SUPER-SECRET-VALUE',
        'private_key' => null,
        'private_key_path' => 'tests/Fixtures/yodu-test-private.pem',
        'channel_id' => '95221',
        'merchant_id' => 'M001',
        'store_id' => 'S001',
        'terminal_id' => 'T001',
    ])]);
    Cache::flush();

    // YODU tiruan: token pertama = OLD-TOKEN (disimpan untuk TC-GQR-04), berikutnya GOOD-TOKEN
    $tokens = ['OLD-TOKEN-1234567890', 'GOOD-TOKEN-1234567890'];
    Http::fake(function (Request $request) use (&$tokens) {
        if (str_ends_with($request->url(), '/access/token')) {
            return Http::response(['responseCode' => '2008000', 'responseMessage' => 'Successful', 'accessToken' => array_shift($tokens) ?? 'GOOD-TOKEN-1234567890']);
        }
        if ($request->header('Authorization')[0] === 'Bearer OLD-TOKEN-1234567890') {
            return Http::response(['responseCode' => '4018000', 'responseMessage' => 'Invalid timestamp format. [iso_time expired or invalid]'], 401);
        }

        $body = json_decode($request->body(), true);

        return match (true) {
            str_ends_with($request->url(), '/mpm/generate') => Http::response([
                'responseCode' => '2008000', 'responseMessage' => 'Successful', 'referenceNo' => 'REF-'.$body['withFee'],
                'responseData' => ['qrContent' => '000201QR-'.$body['withFee'], 'qrReffId' => 'QRID-'.$body['withFee']],
            ]),
            str_ends_with($request->url(), '/mpm/cancelqr') => Http::response(['responseCode' => '2008200', 'responseMessage' => 'Successful', 'responseData' => ['qrStatus' => 3, 'qrStatusMessage' => 'CANCELLED']]),
            str_ends_with($request->url(), '/paystatus') => Http::response([
                'responseCode' => '2008000', 'responseMessage' => 'Successful', 'responseData' => ['latestTransactionStatus' => '00'],
            ]),
        };
    });
});

function prodReport(): string
{
    return Storage::disk('local')->get('yodu-uji-production/laporan.md');
}

test('stage one runs token, generate, cancel and the fee QR, then prints QR A', function () {
    $this->artisan('yodu:uji-production --tahap=1 --fee-type=2 --fee=500')->assertSuccessful();

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/mpm/cancelqr')
        && json_decode($r->body(), true)['originalReferenceNo'] === 'REF-0'
        && json_decode($r->body(), true)['qrReffId'] === 'QRID-0');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/mpm/generate')
        && json_decode($r->body(), true)['withFee'] === '1'
        && json_decode($r->body(), true)['feeType'] === '2'
        && json_decode($r->body(), true)['feeAmount']['value'] === '500.00');

    $report = prodReport();
    expect($report)
        ->toContain('## TC-AT-01')->toContain('## TC-CQR-01')->toContain('Status: **PASSED** (responseCode 2008000)')
        ->toContain('## TC-PR-01')->toContain('refund tidak digunakan')
        ->not->toContain('SUPER-SECRET-VALUE')          // X-CLIENT-KEY disamarkan
        ->not->toContain('GOOD-TOKEN-1234567890')       // Authorization disamarkan
        ->not->toContain('OLD-TOKEN-1234567890');       // accessToken di respons disamarkan

    $this->artisan('yodu:uji-production --qr')->expectsOutput('000201QR-1')->assertSuccessful();
});

test('stage two waits until the stage one token is expired', function () {
    $this->artisan('yodu:uji-production --tahap=1')->assertSuccessful();

    $this->travel(5)->minutes();
    $this->artisan('yodu:uji-production --tahap=2')->assertFailed();

    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/paystatus'));
});

test('stage two checks the expired token, the paid status and the callback of QR A', function () {
    $this->artisan('yodu:uji-production --tahap=1')->assertSuccessful();

    PaymentNotification::create([
        'gateway' => 'yodu', 'merchant_ref' => 'REF-1', 'gateway_status' => '00', 'accepted' => true,
        'headers' => ['content-type' => 'application/json'],
        'body' => json_encode(['originalReferenceNo' => 'REF-1', 'latestTransactionStatus' => '00',
            'amount' => ['value' => '1488.00'], 'payAmount' => ['value' => '1000.00'], 'feeAmount' => ['value' => '500.00'],
            'mdrAmount' => ['value' => '7.00'], 'handlingFee' => ['value' => '5.00']]),
        'response_status' => 200,
        'response' => json_encode(['responseCode' => '2008000', 'responseMessage' => 'Successful']),
    ]);

    $this->travel(16)->minutes();
    $this->artisan('yodu:uji-production --tahap=2')->assertSuccessful();

    $report = prodReport();
    expect($report)
        ->toContain("## TC-GQR-04 · Generate QR MPM\n\nSkenario: Access token sudah expired")
        ->toContain('responseCode 4018000')
        ->toContain('latestTransactionStatus: 00 (Success)')
        ->toContain('feeAmount 500.00 · mdrAmount 7.00')
        ->toContain('Nominal sesuai')
        ->toContain('qrStatus 3')
        ->not->toContain('BELUM DIJALANKAN')
        ->not->toContain('FAILED');
});
