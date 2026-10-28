<?php

use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentNotification;
use App\Payments\Data\TxStatus;
use App\Payments\Gateways\YoduGateway;
use App\Payments\Yodu\YoduClient;
use App\Payments\Yodu\YoduSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const BASE = 'https://sandbox.yodu.test';

beforeEach(function () {
    config(['yodu' => array_merge(config('yodu'), [
        'driver' => 'yodu',
        'base_url' => BASE,
        'client_id' => 'CLIENT-ID',
        'client_secret' => 'CLIENT-SECRET',
        'private_key' => null,
        'private_key_path' => 'tests/Fixtures/yodu-test-private.pem',
        'channel_id' => '95221',
        'merchant_id' => 'M001',
        'store_id' => 'S001',
        'terminal_id' => 'T001',
        'qr_expiry_seconds' => 900,
        'transaction_client_key_header' => false,
    ])]);
    Cache::flush();

    $this->payment = Payment::create([
        'member_id' => Member::factory()->create()->id,
        'gateway' => 'yodu',
        'merchant_ref' => 'KLK0007-261012170105-ABC123',
        'amount' => 300000,
        'status' => Payment::PENDING,
        'gateway_ref' => 'YODU-REF-1',
    ]);
});

function tokenResponse(): array
{
    return ['responseCode' => '2007300', 'accessToken' => str_repeat('t', 64), 'expiresIn' => '900'];
}

test('generate QR sends a signed SNAP request and parses the QR', function () {
    Http::fake([
        BASE.'/openapi/v1.0/access/token' => Http::response(tokenResponse()),
        BASE.'/openapi/v1.0/qr-acq/mpm/generate' => Http::response([
            'responseCode' => '2004700', 'responseMessage' => 'Successful', 'referenceNo' => 'YODU-REF-9',
            'responseData' => ['qrContent' => '00020101021226QRIS', 'qrReffId' => 'QR-9', 'validityPeriod' => '2026-10-12T17:15:00+07:00'],
        ]),
    ]);

    $qr = app(YoduGateway::class, ['config' => config('yodu')])->createQr($this->payment);

    expect($qr->qrString)->toBe('00020101021226QRIS')
        ->and($qr->gatewayRef)->toBe('YODU-REF-9')
        ->and($qr->gatewayQrId)->toBe('QR-9');

    Http::assertSent(function (Request $r) {
        if (! str_ends_with($r->url(), '/qr-acq/mpm/generate')) {
            return false;
        }

        $body = json_decode($r->body(), true);
        $expectedSig = (new YoduSigner('CLIENT-ID', 'CLIENT-SECRET', ''))
            ->symmetric('POST', '/openapi/v1.0/qr-acq/mpm/generate', str_repeat('t', 64), $r->body(), $r->header('X-TIMESTAMP')[0]);

        return $body['partnerReferenceNo'] === 'KLK0007-261012170105-ABC123'
            && $body['amount'] === ['value' => '300000.00', 'currency' => 'IDR']
            && $body['qrType'] === '03'
            && $r->header('X-SIGNATURE')[0] === $expectedSig
            && $r->header('Authorization')[0] === 'Bearer '.str_repeat('t', 64)
            && $r->header('X-PARTNER-ID')[0] === 'CLIENT-ID'
            && $r->header('CHANNEL-ID')[0] === '95221'
            && ! $r->hasHeader('X-CLIENT-KEY')            // secret tidak dikirim di header
            && ! str_contains($r->body(), ' ');            // body = string minified yang di-hash
    });
});

test('access token is cached and refreshed once when rejected', function () {
    Http::fake([
        BASE.'/openapi/v1.0/access/token' => Http::response(tokenResponse()),
        BASE.'/openapi/v1.0/qr-acq/paystatus' => Http::sequence()
            ->push(['responseCode' => '4017300', 'responseMessage' => 'Invalid token'], 401)
            ->push(['responseCode' => '2005100', 'responseData' => ['latestTransactionStatus' => '03']])
            ->push(['responseCode' => '2005100', 'responseData' => ['latestTransactionStatus' => '03']]),
    ]);

    $gateway = app(YoduGateway::class, ['config' => config('yodu')]);
    $gateway->checkStatus($this->payment);
    $gateway->checkStatus($this->payment);

    $tokenCalls = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/access/token'))->count();
    expect($tokenCalls)->toBe(2); // 1x awal + 1x setelah 401; cek status kedua memakai cache
});

test('status codes are mapped and the reference is taken from our own payment', function (string $code, string $expected) {
    Http::fake([
        BASE.'/openapi/v1.0/access/token' => Http::response(tokenResponse()),
        BASE.'/openapi/v1.0/qr-acq/paystatus' => Http::response(['responseCode' => '2005100', 'responseData' => [
            'originalPartnerReferenceNo' => 'MILIK-ORANG-LAIN',
            'latestTransactionStatus' => $code,
            'payAmount' => ['value' => '300000.00'], 'amount' => ['value' => '297900.00'],
            'mdrAmount' => ['value' => '2100.00'], 'issuerName' => 'GoPay', 'paidTime' => '2026-10-12T17:03:41+07:00',
        ]]),
    ]);

    $tx = app(YoduGateway::class, ['config' => config('yodu')])->checkStatus($this->payment);

    expect($tx->status)->toBe($expected)
        ->and($tx->merchantRef)->toBe('KLK0007-261012170105-ABC123')
        ->and($tx->payAmount)->toBe(300000)
        ->and($tx->netAmount)->toBe(297900)
        ->and($tx->channel)->toBe('GoPay');
})->with([
    ['00', TxStatus::SUCCESS],
    ['03', TxStatus::PENDING],
    ['04', TxStatus::REFUNDED],
    ['05', TxStatus::CANCELLED],
    ['06', TxStatus::FAILED],
]);

test('transaction not found means still pending, other errors mean unknown', function () {
    Http::fake([
        BASE.'/openapi/v1.0/access/token' => Http::response(tokenResponse()),
        BASE.'/openapi/v1.0/qr-acq/paystatus' => Http::sequence()
            ->push(['responseCode' => '5008203', 'responseMessage' => 'Transaction Not Found'], 404)
            ->push(['responseCode' => '5005100', 'responseMessage' => 'General Error'], 500),
    ]);

    $gateway = app(YoduGateway::class, ['config' => config('yodu')]);

    expect($gateway->checkStatus($this->payment)?->status)->toBe(TxStatus::PENDING)
        ->and($gateway->checkStatus($this->payment))->toBeNull();
});

test('incomplete YODU configuration fails with a clear message', function () {
    config(['yodu.merchant_id' => null]);

    app(YoduGateway::class, ['config' => config('yodu')])->createQr($this->payment);
})->throws(RuntimeException::class, 'yodu.merchant_id');

/** Callback asli dari sandbox YODU: originalPartnerReferenceNo berisi referenceNo milik YODU, bukan nomor kita. */
function sandboxCallback(): array
{
    return [
        'originalReferenceNo' => 'YODU-REF-1',
        'originalPartnerReferenceNo' => 'YODU-REF-1',
        'qrReffId' => 'QR-1',
        'latestTransactionStatus' => '00',
        'transactionStatusDesc' => 'Success',
        'customerPan' => '936008303260500131',
        'merchantId' => '261000294',
        'destinationAccountName' => 'MOBIL LISTRIK',
        'amount' => ['value' => '300000.0', 'currency' => 'IDR'],
        'payAmount' => ['value' => '300000.0', 'currency' => 'IDR'],
        'feeAmount' => ['value' => '0.0', 'currency' => 'IDR'],
        'mdrAmount' => ['value' => '0.0', 'currency' => 'IDR'],
        'handlingFee' => ['value' => '0', 'currency' => 'IDR'],
        'additionalData' => [],
    ];
}

test('sandbox callback is matched by YODU reference and confirmed by a flat status response', function () {
    $this->payment->update(['gateway_qr_id' => 'QR-1']);
    Http::fake([
        BASE.'/openapi/v1.0/access/token' => Http::response(tokenResponse()),
        // Sandbox menjawab cek status tanpa responseData (datar, seperti callback)
        BASE.'/openapi/v1.0/qr-acq/paystatus' => Http::response(
            ['responseCode' => '2008200', 'responseMessage' => 'Approved'] + sandboxCallback()
        ),
    ]);

    $this->postJson('/api/payments/yodu/callback', sandboxCallback(), ['X-CLIENT-KEY' => 'CLIENT-SECRET'])
        ->assertOk()
        ->assertJsonPath('responseCode', '2005200')
        ->assertJsonPath('responseMessage', 'Successful');

    $log = PaymentNotification::first();
    expect($this->payment->fresh()->status)->toBe(Payment::SUCCESS)
        ->and($log->merchant_ref)->toBe('KLK0007-261012170105-ABC123')
        ->and($log->headers)->not->toHaveKey('x-client-key')
        ->and($log->response_status)->toBe(200)
        ->and(json_decode($log->response, true)['responseCode'])->toBe('2005200');
});

test('failed transaction callback (06) is also answered with 2005200 Successful', function () {
    $this->payment->update(['gateway_qr_id' => 'QR-1']);
    Http::fake([
        BASE.'/openapi/v1.0/access/token' => Http::response(tokenResponse()),
        BASE.'/openapi/v1.0/qr-acq/paystatus' => Http::response(['responseCode' => '2005100', 'responseData' => ['latestTransactionStatus' => '06']]),
    ]);

    $this->postJson('/api/payments/yodu/callback', ['latestTransactionStatus' => '06'] + sandboxCallback(), ['X-CLIENT-KEY' => 'CLIENT-SECRET'])
        ->assertOk()
        ->assertJsonPath('responseCode', '2005200')
        ->assertJsonPath('responseMessage', 'Successful');

    expect($this->payment->fresh()->status)->not->toBe(Payment::SUCCESS);
});

test('callback with a wrong X-CLIENT-KEY is rejected', function () {
    $this->postJson('/api/payments/yodu/callback', sandboxCallback(), ['X-CLIENT-KEY' => 'salah'])
        ->assertUnauthorized()
        ->assertJsonPath('responseCode', '4015200')
        ->assertJsonPath('responseMessage', 'Unauthorized');

    expect($this->payment->fresh()->status)->toBe(Payment::PENDING)
        ->and(PaymentNotification::first()->response_status)->toBe(401);
});

test('probe sends the same signed request with overridable token and headers', function () {
    Http::fake([
        BASE.'/openapi/v1.0/qr-acq/mpm/generate' => Http::response(['responseCode' => '4014701', 'responseMessage' => 'Access Token Invalid'], 401),
    ]);
    $client = YoduClient::make();

    $res = $client->probe('/openapi/v1.0/qr-acq/mpm/generate', ['a' => 1], token: 'BAD-TOKEN', headerOverrides: [
        'X-EXTERNAL-ID' => 'FIXED-ID',
        'ORIGIN' => null,
    ]);

    expect($res['http'])->toBe(401)
        ->and($res['response']['responseCode'])->toBe('4014701')
        ->and($res['url'])->toBe(BASE.'/openapi/v1.0/qr-acq/mpm/generate')
        ->and($res['body'])->toBe('{"a":1}')
        ->and($res['headers']['Authorization'])->toBe('Bearer BAD-TOKEN')
        ->and($res['headers'])->not->toHaveKey('ORIGIN');

    Http::assertSent(fn (Request $r) => $r->hasHeader('X-EXTERNAL-ID', 'FIXED-ID')
        && $r->hasHeader('Authorization', 'Bearer BAD-TOKEN')
        && ! $r->hasHeader('ORIGIN')
        && $r->header('X-SIGNATURE')[0] === (new YoduSigner('CLIENT-ID', 'CLIENT-SECRET', file_get_contents(base_path('tests/Fixtures/yodu-test-private.pem'))))
            ->symmetric('POST', '/openapi/v1.0/qr-acq/mpm/generate', 'BAD-TOKEN', '{"a":1}', $r->header('X-TIMESTAMP')[0]));
});

test('private key from YODU_PRIVATE_KEY (base64 PEM) takes precedence over the file path', function () {
    config([
        'yodu.private_key' => base64_encode(file_get_contents(base_path('tests/Fixtures/yodu-test-private.pem'))),
        'yodu.private_key_path' => 'tests/Fixtures/tidak-ada.pem',
    ]);

    expect(YoduClient::make())->toBeInstanceOf(YoduClient::class);
});

test('YODU_PRIVATE_KEY that is not a base64 PEM fails with a clear message', function () {
    config(['yodu.private_key' => '-----BEGIN PRIVATE KEY----- bukan base64']);

    YoduClient::make();
})->throws(RuntimeException::class, 'YODU_PRIVATE_KEY');
