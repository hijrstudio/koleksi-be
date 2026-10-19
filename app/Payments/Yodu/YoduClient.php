<?php

namespace App\Payments\Yodu;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * HTTP client YODU Open API: access token (di-cache) + request bersignature.
 */
class YoduClient
{
    private const TOKEN_CACHE_KEY = 'yodu:access_token';

    /** Token berlaku 900 detik (spec) → cache 14 menit supaya tidak dipakai di detik-detik terakhir. */
    private const TOKEN_TTL_SECONDS = 840;

    public function __construct(private array $config, private YoduSigner $signer) {}

    public static function make(): self
    {
        $c = config('yodu');

        foreach (['client_id', 'client_secret', 'channel_id', 'merchant_id', 'store_id', 'terminal_id'] as $key) {
            if (blank($c[$key] ?? null)) {
                throw new RuntimeException("Konfigurasi YODU belum lengkap: yodu.{$key} (.env YODU_".strtoupper($key).').');
            }
        }

        return new self($c, new YoduSigner(
            $c['client_id'],
            $c['client_secret'],
            self::privateKeyPem($c),
            $c['signature_encoding'] ?? 'base64',
        ));
    }

    /** YODU_PRIVATE_KEY (PEM di-base64, untuk server) didahulukan; kalau kosong baru baca file YODU_PRIVATE_KEY_PATH. */
    private static function privateKeyPem(array $c): string
    {
        if (filled($c['private_key'] ?? null)) {
            $pem = base64_decode((string) $c['private_key'], true);
            if (! $pem || ! str_contains($pem, '-----BEGIN')) {
                throw new RuntimeException('YODU_PRIVATE_KEY bukan PEM yang di-base64 (generate: base64 -w0 yodu-private.pem).');
            }

            return $pem;
        }

        $path = base_path($c['private_key_path']);
        $pem = is_readable($path) ? file_get_contents($path) : false;
        if (! $pem) {
            throw new RuntimeException("Private key YODU tidak ditemukan / tidak bisa dibaca: {$c['private_key_path']}");
        }

        return $pem;
    }

    /** Waktu Jakarta ISO-8601: 2026-10-08T12:00:00+07:00 */
    public static function timestamp(): string
    {
        return now('Asia/Jakarta')->format('Y-m-d\TH:i:sP');
    }

    /** Unik per hari, maks 36 karakter (X-EXTERNAL-ID & partnerReferenceNo untuk cek status/cancel). */
    public static function uniqueRef(string $prefix = ''): string
    {
        return $prefix.now('Asia/Jakarta')->format('YmdHisv').random_int(100000, 999999);
    }

    // ------------------------------------------------------------------ token

    public function accessToken(bool $fresh = false): string
    {
        if ($fresh) {
            Cache::forget(self::TOKEN_CACHE_KEY);
        }

        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addSeconds(self::TOKEN_TTL_SECONDS), function () {
            $timestamp = self::timestamp();
            $body = ['grantType' => 'client_credentials', 'additionalInfo' => (object) []];

            $res = $this->http()
                ->withHeaders([
                    'X-TIMESTAMP' => $timestamp,
                    'X-PARTNER-ID' => $this->config['client_id'],
                    'X-CLIENT-KEY' => $this->config['client_id'],   // dokumen YODU tidak konsisten; header ekstra aman
                    'X-SIGNATURE' => $this->signer->asymmetric($timestamp),
                ])
                ->withBody(json_encode($body), 'application/json')
                ->post($this->config['token_path']);

            $json = $res->json() ?? [];
            if (! $res->successful() || empty($json['accessToken'])) {
                Log::error('YODU token gagal', ['status' => $res->status(), 'code' => $json['responseCode'] ?? null, 'message' => $json['responseMessage'] ?? null]);
                throw new RuntimeException('Gagal mengambil access token YODU: '.($json['responseMessage'] ?? $res->status()));
            }

            return $json['accessToken'];
        });
    }

    // ------------------------------------------------------------------ request

    /**
     * POST bersignature. Kalau token ditolak (401), ambil token baru dan ulang sekali.
     *
     * @return array{http: int, body: array, request: array}
     */
    public function post(string $path, array $body): array
    {
        $res = $this->send($path, $body, $this->accessToken());

        if ($res->status() === 401) {
            $res = $this->send($path, $body, $this->accessToken(fresh: true));
        }

        return ['http' => $res->status(), 'body' => $res->json() ?? [], 'request' => $body];
    }

    private function send(string $path, array $body, string $token): Response
    {
        $json = YoduSigner::minify($body);
        $timestamp = self::timestamp();

        $headers = [
            'X-TIMESTAMP' => $timestamp,
            'X-SIGNATURE' => $this->signer->symmetric('POST', $path, $token, $json, $timestamp),
            'X-PARTNER-ID' => $this->config['client_id'],
            'X-EXTERNAL-ID' => self::uniqueRef(),
            'CHANNEL-ID' => $this->config['channel_id'],
            'ORIGIN' => $this->config['origin'] ?? parse_url((string) config('app.url'), PHP_URL_HOST),
        ];

        // SNAP tidak mewajibkan secret di header (autentikasi = signature HMAC). Nyalakan hanya kalau YODU memintanya.
        if ($this->config['transaction_client_key_header'] ?? false) {
            $headers['X-CLIENT-KEY'] = $this->config['client_secret'];
        }

        return $this->http()
            ->withToken($token)
            ->withHeaders($headers)
            ->withBody($json, 'application/json')   // kirim string yang SAMA dengan yang di-hash
            ->post($path);
    }

    /** responseCode sukses: HTTP 200 + kode kasus "00" (2008200, 2005100, 2007500 ...). */
    public static function isSuccess(array $body): bool
    {
        $rc = (string) ($body['responseCode'] ?? '');

        return strlen($rc) === 7 && str_starts_with($rc, '200') && str_ends_with($rc, '00');
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->config['base_url'], '/'))
            ->timeout($this->config['timeout'] ?? 30)
            ->connectTimeout(10)
            ->acceptJson();
    }
}
