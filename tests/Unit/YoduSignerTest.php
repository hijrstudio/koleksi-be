<?php

use App\Payments\Yodu\YoduSigner;

// Pasangan kunci KHUSUS TEST (tidak dipakai di mana pun selain test ini). Disimpan sebagai fixture karena
// openssl_pkey_new() butuh openssl.cnf yang tidak selalu ada (mis. PHP Windows/Laragon).
beforeEach(function () {
    $this->privatePem = file_get_contents(__DIR__.'/../Fixtures/yodu-test-private.pem');
    $this->publicPem = file_get_contents(__DIR__.'/../Fixtures/yodu-test-public.pem');
});

test('asymmetric signature (SHA256withRSA) can be verified with the public key', function () {
    $signer = new YoduSigner('26038957', 'secret', $this->privatePem);
    $ts = '2026-10-12T17:00:00+07:00';

    $sig = base64_decode($signer->asymmetric($ts));

    expect(openssl_verify('26038957|'.$ts, $sig, $this->publicPem, OPENSSL_ALGO_SHA256))->toBe(1);
});

test('symmetric signature matches the HMAC-SHA512 formula from the spec', function () {
    $secret = '3ea53726-5862-441a-8df1-fe004390236d';
    $signer = new YoduSigner('26038957', $secret, $this->privatePem);
    $body = YoduSigner::minify(['partnerReferenceNo' => 'KLK0007-261012170105-A1B', 'amount' => ['value' => '150000.00', 'currency' => 'IDR']]);
    $ts = '2026-10-12T17:00:00+07:00';

    $expected = base64_encode(hash_hmac('sha512',
        'POST:/openapi/v1.0/qr-acq/mpm/generate:TOKEN:'.hash('sha256', $body).':'.$ts, $secret, true));

    expect($signer->symmetric('post', '/openapi/v1.0/qr-acq/mpm/generate', 'TOKEN', $body, $ts))->toBe($expected)
        ->and($body)->not->toContain(' ');
});

test('hex encoding option returns lowercase hex of the same HMAC', function () {
    $signer = new YoduSigner('id', 'secret', $this->privatePem, 'hex');

    $hex = $signer->symmetric('POST', '/x', 'T', '', '2026-10-12T17:00:00+07:00');

    expect($hex)->toMatch('/^[0-9a-f]{128}$/')
        ->and($hex)->toBe(hash_hmac('sha512', 'POST:/x:T:'.hash('sha256', '').':2026-10-12T17:00:00+07:00', 'secret'));
});

test('invalid private key is rejected', function () {
    (new YoduSigner('id', 'secret', 'bukan-pem'))->asymmetric('2026-10-12T17:00:00+07:00');
})->throws(RuntimeException::class);
