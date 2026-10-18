<?php

namespace App\Payments\Yodu;

use RuntimeException;

/**
 * Signature YODU Open API (SNAP B2B) — Spec Doc Security v1.3.
 * Murni fungsi kriptografi, tanpa dependency Laravel (mudah di-unit-test).
 */
class YoduSigner
{
    public function __construct(
        private string $clientId,
        #[\SensitiveParameter] private string $clientSecret,
        #[\SensitiveParameter] private string $privateKeyPem,
        private string $encoding = 'base64',   // 'base64' (bagian Generate) | 'hex' (bagian Validasi) — konfirmasi ke YODU
    ) {}

    /**
     * Asymmetric — untuk API Access Token.
     * X-SIGNATURE = Base64( SHA256withRSA(privateKey, clientId + "|" + X-TIMESTAMP) ), RSA PKCS#1 v1.5.
     */
    public function asymmetric(string $timestamp): string
    {
        $key = openssl_pkey_get_private($this->privateKeyPem);
        if ($key === false) {
            throw new RuntimeException('Private key YODU tidak valid.');
        }

        if (! openssl_sign($this->clientId.'|'.$timestamp, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Gagal membuat signature asymmetric YODU.');
        }

        return base64_encode($signature);
    }

    /**
     * Symmetric — untuk semua API transaksi.
     * StringToSign = METHOD:RelativeUrl:AccessToken:lowercase(hex(sha256(minifiedBody))):Timestamp
     * X-SIGNATURE  = Base64( HMAC-SHA512(clientSecret, StringToSign) )
     */
    public function symmetric(string $method, string $relativeUrl, string $accessToken, string $minifiedBody, string $timestamp): string
    {
        $stringToSign = implode(':', [
            strtoupper($method),
            $relativeUrl,
            $accessToken,
            strtolower(hash('sha256', $minifiedBody)),
            $timestamp,
        ]);

        $raw = hash_hmac('sha512', $stringToSign, $this->clientSecret, true);

        return $this->encoding === 'hex' ? bin2hex($raw) : base64_encode($raw);
    }

    /** JSON tanpa whitespace — string INI yang dikirim sebagai body DAN di-hash. */
    public static function minify(array $body): string
    {
        return $body === []
            ? ''
            : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
