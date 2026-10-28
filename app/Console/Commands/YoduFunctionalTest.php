<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentNotification;
use App\Payments\Gateways\YoduGateway;
use App\Payments\Yodu\YoduClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Uji fungsional YODU QRIS ACQ MPM ("[OA QRIS ACQ] Uji Fungsionalitas Koleksi"): menjalankan test case yang
 * berlaku untuk KOLEKSI ke SANDBOX YODU, lalu menulis laporan per test case (URL, header, body, response) dalam
 * format template YODU, siap disalin ke xlsx.
 *
 *   18.1–18.7  dijalankan langsung (Generate QR + skenario error)
 *   18.18/19   diambil dari log callback (payment_notifications): butuh notifikasi sukses / gagal dari sandbox
 *   18.24      cek status transaksi yang sudah dibayar (Payment SUCCESS terakhir, atau --ref)
 *
 * Hanya untuk sandbox: ditolak kalau YODU_BASE_URL bukan host sandbox. Laporan berisi header asli (termasuk
 * token & X-CLIENT-KEY sandbox, sesuai template YODU) dan disimpan di storage/app/private (tidak di-commit).
 */
class YoduFunctionalTest extends Command
{
    protected $signature = 'yodu:uji-fungsional
        {--case=* : Nomor test case tertentu saja, mis. --case=18.1 --case=18.6}
        {--amount=10000 : Nominal Generate QR (rupiah)}
        {--ref= : merchant_ref transaksi yang sudah dibayar untuk 18.24}';

    protected $description = 'Jalankan uji fungsional YODU QRIS MPM ke sandbox dan tulis laporan siap-salin';

    private const DIRECTORY = 'yodu-uji-fungsional';

    private YoduClient $client;

    private YoduGateway $gateway;

    public function handle(): int
    {
        $baseUrl = (string) config('yodu.base_url');
        if (! str_contains(strtolower((string) parse_url($baseUrl, PHP_URL_HOST)), 'sandbox')) {
            $this->error("Hanya untuk sandbox YODU. YODU_BASE_URL sekarang: {$baseUrl}");

            return self::FAILURE;
        }

        try {
            $this->client = YoduClient::make();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->gateway = new YoduGateway(config('yodu'), $this->client);

        $only = $this->option('case');
        $results = [];

        foreach ($this->cases() as $no => [$title, $expected, $run]) {
            if ($only !== [] && ! in_array($no, $only, true)) {
                continue;
            }

            $this->line("▶ {$no} {$title}");

            try {
                $result = $run();
            } catch (Throwable $e) {
                $result = ['error' => $e->getMessage()];
            }

            $results[] = ['no' => $no, 'title' => $title, 'expected' => $expected] + $result;
        }

        $path = self::DIRECTORY.'/'.now('Asia/Jakarta')->format('Y-m-d_His').'.md';
        Storage::disk('local')->put($path, $this->report($results));

        $this->newLine();
        $this->table(['No', 'Expected', 'Response', 'Pesan YODU', 'Hasil'], array_map(fn (array $r) => [
            $r['no'], $r['expected'][0], $r['code'] ?? '-', Str::limit((string) ($r['message'] ?? $r['skipped'] ?? $r['error'] ?? ''), 60), $this->verdict($r),
        ], $results));
        $this->info('Laporan: '.Storage::disk('local')->path($path));

        return self::SUCCESS;
    }

    /**
     * no => [judul, [kode expected, pesan expected], fungsi uji].
     *
     * @return array<string, array{0: string, 1: array{0: string, 1: string}, 2: callable}>
     */
    private function cases(): array
    {
        $generate = YoduGateway::PATH_GENERATE;

        return [
            '18.1' => ['Any Service: Access Token Invalid', ['401xx01', 'Access Token Invalid'],
                fn () => $this->send($generate, $this->qrBody(), token: bin2hex(random_bytes(32)))],
            '18.2' => ['Any Service: Unauthorized Signature', ['401xx00', 'Unauthorized Signature'],
                fn () => $this->send($generate, $this->qrBody(), headers: ['X-SIGNATURE' => base64_encode(random_bytes(64))])],
            '18.3' => ['Any Service: Missing Mandatory Field', ['400xx02', 'Invalid Mandatory Field {...}'],
                fn () => $this->firstMatching('400xx02', collect(['partnerReferenceNo', 'merchantId', 'amount', 'qrType', 'terminalId'])
                    ->mapWithKeys(fn (string $field) => ["tanpa {$field}" => fn () => $this->send($generate, collect($this->qrBody())->except($field)->all())])
                    ->all())],
            '18.4' => ['Any Service: Invalid Field Format', ['400xx01', 'Invalid Field Format {...}'],
                fn () => $this->firstMatching('400xx01', [
                    'amount.value "abc"' => fn () => $this->send($generate, ['amount' => ['value' => 'abc', 'currency' => 'IDR']] + $this->qrBody()),
                    'amount.value tanpa desimal' => fn () => $this->send($generate, ['amount' => ['value' => (string) $this->option('amount'), 'currency' => 'IDR']] + $this->qrBody()),
                    'qrType "XX"' => fn () => $this->send($generate, ['qrType' => 'XX'] + $this->qrBody()),
                    'expiryPeriod "abc"' => fn () => $this->send($generate, ['expiryPeriod' => 'abc'] + $this->qrBody()),
                ])],
            '18.5' => ['Any Service: Cannot use the same X-EXTERNAL-ID', ['409xx00', 'Conflict'], function () use ($generate) {
                $externalId = YoduClient::uniqueRef('UJI');
                $this->send($generate, $this->qrBody(), headers: ['X-EXTERNAL-ID' => $externalId]); // request pertama

                return $this->send($generate, $this->qrBody(), headers: ['X-EXTERNAL-ID' => $externalId]);
            }],
            '18.6' => ['QR MPM - Generate QR: Show QR Code', ['200xx00', 'Successful'],
                fn () => $this->send($generate, $this->qrBody())],
            '18.7' => ['QR MPM - Generate QR: Invalid Merchant', ['4044708', 'Invalid Merchant'],
                fn () => $this->firstMatching('4044708', [
                    'merchantId tidak terdaftar' => fn () => $this->send($generate, ['merchantId' => '999999999'] + $this->qrBody()),
                    'merchantId + storeId tidak terdaftar' => fn () => $this->send($generate, ['merchantId' => '999999999', 'storeId' => '999999999000'] + $this->qrBody()),
                ])],
            '18.18' => ['Payment Notification: Successful Transaction (00)', ['2005200', 'Successful'],
                fn () => $this->callback('00')],
            '18.19' => ['Payment Notification: Failed Transaction (06)', ['2005200', 'Successful'],
                fn () => $this->callback('06')],
            '18.24' => ['Transaction Status Inquiry: transaksi berhasil (00)', ['200xx00', 'Successful'],
                fn () => $this->statusInquiry()],
        ];
    }

    /** @return array<string, mixed> */
    private function qrBody(): array
    {
        return $this->gateway->qrRequestBody(YoduClient::uniqueRef('UJI'), (int) $this->option('amount'));
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string|null>  $headers
     * @return array<string, mixed>
     */
    private function send(string $path, array $body, ?string $token = null, array $headers = []): array
    {
        $res = $this->client->probe($path, $body, $token, $headers);

        return $res + [
            'code' => is_array($res['response']) ? ($res['response']['responseCode'] ?? null) : null,
            'message' => is_array($res['response']) ? ($res['response']['responseMessage'] ?? null) : null,
        ];
    }

    /**
     * Jalankan variasi request berurutan; berhenti di yang pertama menghasilkan kode expected. Semua percobaan
     * dicatat di laporan supaya terlihat field mana yang divalidasi YODU.
     *
     * @param  array<string, callable(): array<string, mixed>>  $attempts  label => request
     * @return array<string, mixed>
     */
    private function firstMatching(string $expected, array $attempts): array
    {
        $pattern = '/^'.str_replace('xx', '\d\d', $expected).'$/';
        $tried = [];
        $result = [];

        foreach ($attempts as $label => $attempt) {
            $result = $attempt();
            $tried[] = "{$label} → ".($result['code'] ?? 'HTTP '.$result['http']).' '.($result['message'] ?? '');

            if (preg_match($pattern, (string) ($result['code'] ?? ''))) {
                break;
            }
        }

        return $result + ['note' => "Percobaan:\n- ".implode("\n- ", $tried)];
    }

    /** @return array<string, mixed> */
    private function callback(string $status): array
    {
        $log = PaymentNotification::query()
            ->where('gateway', 'yodu')
            ->where('gateway_status', $status)
            ->where('accepted', true)
            ->latest('id')
            ->first();

        $case = $status === '00' ? '18.18' : '18.19';

        if (! $log) {
            return ['skipped' => "Belum ada callback sandbox dengan latestTransactionStatus \"{$status}\". "
                ."Picu notifikasi dari sandbox YODU, lalu jalankan ulang: --case={$case}"];
        }

        if ($log->response === null) {
            return ['skipped' => "Callback \"{$status}\" terakhir (log #{$log->id}) masuk sebelum respons kita dicatat. "
                ."Picu notifikasi baru dari sandbox YODU, lalu jalankan ulang: --case={$case}"];
        }

        $response = json_decode((string) $log->response, true);

        return [
            'url' => route('api.payments.yodu.callback'),
            'headers' => $log->headers ?? [],
            'body' => $log->body,
            'http' => $log->response_status,
            'response' => $response ?? $log->response,
            'code' => is_array($response) ? ($response['responseCode'] ?? null) : null,
            'message' => is_array($response) ? ($response['responseMessage'] ?? null) : null,
            'note' => "Callback diterima {$log->created_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s')} WIB (log #{$log->id}).",
        ];
    }

    /** @return array<string, mixed> */
    private function statusInquiry(): array
    {
        $payment = Payment::query()
            ->where('gateway', 'yodu')
            ->when($this->option('ref'), fn ($q, $ref) => $q->where('merchant_ref', $ref), fn ($q) => $q->where('status', Payment::SUCCESS))
            ->latest('id')
            ->first();

        if (! $payment) {
            return ['skipped' => 'Belum ada transaksi sandbox yang sudah dibayar. Bayar satu QR iuran di sandbox, '
                .'lalu jalankan ulang: --case=18.24 (atau --ref=<merchant_ref>)'];
        }

        return $this->send(YoduGateway::PATH_STATUS, $this->gateway->statusRequestBody($payment))
            + ['note' => "Transaksi {$payment->merchant_ref}."];
    }

    /** @param  array<string, mixed>  $result */
    private function verdict(array $result): string
    {
        if (isset($result['skipped'])) {
            return 'DILEWATI';
        }
        if (isset($result['error'])) {
            return 'ERROR';
        }

        $pattern = '/^'.str_replace('xx', '\d\d', $result['expected'][0]).'$/';

        return preg_match($pattern, (string) ($result['code'] ?? '')) ? 'SESUAI' : 'TIDAK SESUAI';
    }

    /** @param  list<array<string, mixed>>  $results */
    private function report(array $results): string
    {
        $out = ['# Uji Fungsionalitas YODU QRIS ACQ MPM: KOLEKSI', '',
            'Tanggal pengujian: '.now('Asia/Jakarta')->format('Y-m-d H:i').' WIB', 'Base URL: '.config('yodu.base_url'), ''];

        foreach ($results as $r) {
            $out[] = "## {$r['no']} {$r['title']}";
            $out[] = '';
            $out[] = "Expected: {$r['expected'][0]} \"{$r['expected'][1]}\"  ";
            $out[] = 'Hasil: '.$this->verdict($r).(isset($r['code']) ? " (responseCode {$r['code']})" : '');
            if (isset($r['note'])) {
                $out[] = '';
                $out[] = $r['note'];
            }
            $out[] = '';

            if (isset($r['skipped']) || isset($r['error'])) {
                $out[] = $r['skipped'] ?? 'Error: '.$r['error'];
                $out[] = '';

                continue;
            }

            $host = parse_url($r['url'], PHP_URL_HOST);
            $out[] = '```';
            $out[] = 'URL Endpoint:';
            $out[] = "POST {$r['url']}";
            $out[] = "Host: {$host}";
            $out[] = '';
            $out[] = 'Header Request:';
            $out[] = self::pretty($r['headers']);
            $out[] = '';
            $out[] = 'Request Body:';
            $out[] = self::pretty($r['body']);
            $out[] = '';
            $out[] = "Response Body (HTTP {$r['http']}):";
            $out[] = self::pretty($r['response']);
            $out[] = '```';
            $out[] = '';
        }

        return implode("\n", $out)."\n";
    }

    /** JSON rapi seperti contoh template YODU; string JSON di-decode dulu. */
    private static function pretty(mixed $value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return Str::limit($value, 5000);
            }
            $value = $decoded;
        }

        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
