<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentNotification;
use App\Payments\Gateways\YoduGateway;
use App\Payments\Yodu\YoduClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Test script production YODU ("PTR_TestCase_YODU_OpenAPI_Acquirer"). Dijalankan bertahap karena butuh pembayaran
 * sungguhan dan token yang sudah kedaluwarsa:
 *
 *   --tahap=1  TC-AT-01, TC-GQR-02, TC-CQR-01 (cancel QR dari TC-GQR-02), TC-GQR-01 (QR A dengan fee → dibayar)
 *   --qr       cetak qrContent QR A saja (pipe ke `qrencode -t ansiutf8` supaya bisa di-scan)
 *   --tahap=2  ≥ 15 menit setelah tahap 1: TC-GQR-04 (token tahap 1), TC-CS-01 & TC-CALLBACK-01 (QR A)
 *
 * TC-PR-01/02/04 (refund) dilewati: iuran tidak bisa di-refund. QR uji tidak disimpan sebagai Payment (tidak terkait
 * member). Laporan menyamarkan Client Secret (X-CLIENT-KEY) & access token karena ini kredensial production.
 */
class YoduProductionTest extends Command
{
    protected $signature = 'yodu:uji-production
        {--tahap= : 1 atau 2}
        {--qr : Cetak qrContent QR A saja}
        {--amount=1000 : Nominal QR uji (rupiah)}
        {--fee=500 : feeAmount QR A (rupiah)}
        {--fee-type=1 : feeType QR A, sesuai Spec Doc QRIS ACQ MPM YODU}
        {--paksa : Jalankan tahap 2 walau token tahap 1 belum 15 menit}';

    protected $description = 'Jalankan test script production YODU QRIS ACQ MPM dan tulis laporan siap-salin';

    private const DIRECTORY = 'yodu-uji-production';

    private const TOKEN_EXPIRY_SECONDS = 900;

    /** Test Case ID => [endpoint, skenario, expected]. Urutan = urutan sheet YODU. */
    private const CASES = [
        'TC-AT-01' => ['Access Token', 'Generate access token dengan kredensial & signature valid', 'responseCode = 2008000 Successful'],
        'TC-GQR-01' => ['Generate QR MPM', 'Generate QR dinamis MPM dengan data lengkap & valid', 'responseCode = 2008000 Successful'],
        'TC-GQR-02' => ['Generate QR MPM', 'Generate QR tanpa fee (withFee=0)', 'responseCode = 2008000 Successful'],
        'TC-GQR-04' => ['Generate QR MPM', 'Access token sudah expired (>15 menit sejak diterbitkan)', 'responseCode = 4018000 Invalid timestamp format. [iso_time expired or invalid]'],
        'TC-CQR-01' => ['Cancel Dynamic QR MPM', 'Membatalkan QR dinamis yang masih aktif (belum dibayar)', 'responseCode = 2008000 Successful'],
        'TC-CS-01' => ['Check Status', 'Cek status transaksi yang sudah berhasil (paid)', 'responseCode = 00 Approved'],
        'TC-PR-01' => ['Payment Refund', 'Refund penuh atas transaksi yang sudah sukses', 'responseCode = 00 Approve'],
        'TC-PR-02' => ['Payment Refund', 'Refund sebagian (partial refund)', 'responseCode = 00 Approve'],
        'TC-PR-04' => ['Payment Refund', 'refundAmount melebihi sisa nominal yang dapat direfund', 'responseCode = 5007501 INVALID_AMOUNT'],
        'TC-CALLBACK-01' => ['Callback', 'Pembayaran berhasil, cek nominal transaksi, fee & MDR di callback', 'responseCode = 2008000 Successful'],
    ];

    private const REFUND_SKIPPED = 'Dilewati: refund tidak digunakan (pembayaran iuran KOLEKSI tidak bisa di-refund).';

    private YoduClient $client;

    private YoduGateway $gateway;

    /** @var array<string, mixed> */
    private array $state = [];

    public function handle(): int
    {
        $this->state = $this->loadState();

        if ($this->option('qr')) {
            $qr = $this->state['qr_a']['qr_content'] ?? null;
            if (! $qr) {
                $this->error('QR A belum ada. Jalankan --tahap=1 dulu.');

                return self::FAILURE;
            }
            $this->output->writeln($qr);

            return self::SUCCESS;
        }

        $stage = (string) $this->option('tahap');
        if (! in_array($stage, ['1', '2'], true)) {
            $this->error('Pilih --tahap=1 atau --tahap=2.');

            return self::FAILURE;
        }

        try {
            $this->client = YoduClient::make();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->gateway = new YoduGateway(config('yodu'), $this->client);

        if ($stage === '1') {
            $this->stageOne();
        } elseif (! $this->stageTwo()) {
            return self::FAILURE;
        }

        $this->saveState();
        $path = self::DIRECTORY.'/laporan.md';
        Storage::disk('local')->put($path, $this->report());

        $this->newLine();
        $this->table(['Test Case', 'Response', 'Pesan YODU', 'Status'], collect(self::CASES)->keys()
            ->filter(fn (string $id) => isset($this->state['results'][$id]))
            ->map(fn (string $id) => [
                $id,
                $this->state['results'][$id]['code'] ?? '-',
                Str::limit((string) ($this->state['results'][$id]['message'] ?? $this->state['results'][$id]['error'] ?? ''), 60),
                $this->state['results'][$id]['status'],
            ])->values()->all());
        $this->info('Laporan: '.Storage::disk('local')->path($path));

        return self::SUCCESS;
    }

    private function stageOne(): void
    {
        $this->state = ['started_at' => now()->toIso8601String(), 'results' => []];

        $this->runCase('TC-AT-01', function () {
            $result = $this->withCode($this->client->tokenProbe());
            $token = is_array($result['response']) ? ($result['response']['accessToken'] ?? null) : null;
            if ($token) {
                $this->state['old_token'] = ['token' => $token, 'issued_at' => now()->toIso8601String()];
            }

            return $result + ['status' => $token && YoduClient::isSuccess((array) $result['response']) ? 'PASSED' : 'FAILED'];
        });

        $this->runCase('TC-GQR-02', function () {
            $result = $this->send(YoduGateway::PATH_GENERATE, $this->qrBody());
            $this->state['qr_cancel'] = $this->qrState($result);

            return $result + ['status' => $this->successStatus($result)];
        });

        $this->runCase('TC-CQR-01', function () {
            $qr = $this->state['qr_cancel'] ?? null;
            if (! $qr) {
                return ['error' => 'QR dari TC-GQR-02 gagal dibuat, jadi tidak ada QR untuk dibatalkan.', 'status' => 'FAILED'];
            }

            $result = $this->send(YoduGateway::PATH_CANCEL, $this->gateway->cancelRequestBody($this->payment($qr), 'Uji cancel QR'));
            // Spec MPM: cancel berhasil → responseData.qrStatus 3 (Cancelled)
            $qrStatus = (string) (is_array($result['response']) ? ($result['response']['responseData']['qrStatus'] ?? '-') : '-');

            return $result + ['status' => $this->successStatus($result) === 'PASSED' && $qrStatus === '3' ? 'PASSED' : 'FAILED',
                'note' => "Membatalkan QR dari TC-GQR-02: referenceNo {$qr['reference_no']}, qrReffId {$qr['qr_reff_id']}, qrStatus {$qrStatus}."];
        });

        $this->runCase('TC-GQR-01', function () {
            $body = [
                'withFee' => '1',
                'feeType' => (string) $this->option('fee-type'),
                'feeAmount' => ['value' => number_format((int) $this->option('fee'), 2, '.', ''), 'currency' => 'IDR'],
            ] + $this->qrBody();
            $result = $this->send(YoduGateway::PATH_GENERATE, $body);
            $this->state['qr_a'] = $this->qrState($result);

            // Spec MPM: feeType 1 = nominal, 2 = persen dari amount. Tip (fee) ditambahkan ke amount saat dibayar.
            $amount = (int) $this->option('amount');
            $fee = $body['feeType'] === '2' ? $amount * (int) $this->option('fee') / 100 : (int) $this->option('fee');
            if ($this->state['qr_a']) {
                $this->state['qr_a'] += ['amount' => $amount, 'fee' => $fee];
            }

            return $result + ['status' => $this->successStatus($result),
                'note' => 'QR A (dengan fee) dipakai lagi untuk TC-CS-01 & TC-CALLBACK-01 setelah dibayar. '
                    .'Total dibayar customer: amount '.$amount.' + fee '.$fee.' = '.($amount + $fee).'.'];
        });

        foreach (['TC-PR-01', 'TC-PR-02', 'TC-PR-04'] as $id) {
            $this->state['results'][$id] = ['status' => 'DILEWATI', 'skipped' => self::REFUND_SKIPPED];
        }

        $this->newLine();
        if (isset($this->state['qr_a'])) {
            $this->info('Langkah berikutnya: bayar QR A sebelum kedaluwarsa. Tampilkan QR-nya di terminal host:');
            $this->line('  docker compose exec -T app php artisan yodu:uji-production --qr | qrencode -t ansiutf8');
            $this->line('Setelah itu tunggu minimal 15 menit sejak tahap 1, lalu jalankan --tahap=2.');
        } else {
            $this->warn('QR A gagal dibuat. Cek TC-GQR-01 di laporan (mungkin --fee-type salah), lalu ulangi --tahap=1.');
        }
    }

    private function stageTwo(): bool
    {
        if (! isset($this->state['results'])) {
            $this->error('Jalankan --tahap=1 dulu.');

            return false;
        }

        $issuedAt = isset($this->state['old_token']['issued_at']) ? Carbon::parse($this->state['old_token']['issued_at']) : null;
        $age = $issuedAt ? (int) $issuedAt->diffInSeconds(now()) : 0;
        if ($issuedAt && $age < self::TOKEN_EXPIRY_SECONDS && ! $this->option('paksa')) {
            $this->error('Token tahap 1 baru berumur '.intdiv($age, 60).' menit. Tunggu '
                .(int) ceil((self::TOKEN_EXPIRY_SECONDS - $age) / 60).' menit lagi (token expired = 15 menit), lalu ulangi --tahap=2.');

            return false;
        }

        $this->runCase('TC-GQR-04', function () use ($age) {
            $token = $this->state['old_token']['token'] ?? null;
            if (! $token) {
                return ['error' => 'Token dari TC-AT-01 tidak ada.', 'status' => 'FAILED'];
            }

            $result = $this->send(YoduGateway::PATH_GENERATE, $this->qrBody(), token: $token);

            return $result + ['status' => str_starts_with((string) ($result['code'] ?? ''), '401') ? 'PASSED' : 'FAILED',
                'note' => 'Memakai access token dari TC-AT-01 yang diterbitkan '.intdiv($age, 60).' menit sebelumnya.'];
        });

        $qr = $this->state['qr_a'] ?? null;

        $this->runCase('TC-CS-01', function () use ($qr) {
            if (! $qr) {
                return ['error' => 'QR A (TC-GQR-01) tidak ada.', 'status' => 'FAILED'];
            }

            $result = $this->send(YoduGateway::PATH_STATUS, $this->gateway->statusRequestBody($this->payment($qr)));
            $response = is_array($result['response']) ? $result['response'] : [];
            $txStatus = (string) ($response['responseData']['latestTransactionStatus'] ?? $response['latestTransactionStatus'] ?? '-');

            return $result + ['status' => YoduClient::isSuccess($response) && $txStatus === '00' ? 'PASSED' : 'FAILED',
                'note' => "QR A {$qr['partner_reference_no']}, latestTransactionStatus: {$txStatus}"
                    .($txStatus === '00' ? ' (Success).' : '. Belum dibayar? Bayar QR A lalu ulangi --tahap=2.')];
        });

        $this->runCase('TC-CALLBACK-01', fn () => $qr ? $this->callback($qr) : ['error' => 'QR A (TC-GQR-01) tidak ada.', 'status' => 'FAILED']);

        return true;
    }

    /** @param  callable(): array<string, mixed>  $test */
    private function runCase(string $id, callable $test): void
    {
        $this->line("▶ {$id} ".self::CASES[$id][1]);

        try {
            $result = $test();
        } catch (Throwable $e) {
            $result = ['error' => $e->getMessage(), 'status' => 'FAILED'];
        }

        $this->state['results'][$id] = $result + ['tested_at' => now('Asia/Jakarta')->format('Y-m-d H:i:s')];
    }

    /** @return array<string, mixed> */
    private function callback(array $qr): array
    {
        $refs = array_filter([$qr['reference_no'], $qr['partner_reference_no'], $qr['qr_reff_id']]);

        $log = PaymentNotification::query()
            ->where('gateway', 'yodu')
            ->where(fn ($q) => collect($refs)->each(fn (string $ref) => $q->orWhere('body', 'like', '%'.$ref.'%')))
            ->latest('id')
            ->first();

        if (! $log) {
            return ['error' => 'Belum ada callback YODU untuk QR A. Pastikan QR A sudah dibayar & Callback ACQ URL di OACP benar.', 'status' => 'FAILED'];
        }

        $body = json_decode((string) $log->body, true) ?: [];
        $response = json_decode((string) $log->response, true);
        $money = fn (string $key) => $body[$key]['value'] ?? $body[$key] ?? '-';

        // Spec MPM callback: payAmount = amount QR, feeAmount = tip, amount = bersih = payAmount + fee − MDR − handlingFee
        $value = fn (string $key) => (float) ($body[$key]['value'] ?? 0);
        $amountsMatch = isset($qr['amount'])
            && abs($value('payAmount') - $qr['amount']) < 0.01
            && abs($value('feeAmount') - $qr['fee']) < 0.01
            && abs($value('amount') - ($value('payAmount') + $value('feeAmount') - $value('mdrAmount') - $value('handlingFee'))) < 0.01;

        return [
            'url' => route('api.payments.yodu.callback'),
            'headers' => $log->headers ?? [],
            'body' => $log->body,
            'http' => $log->response_status,
            'response' => $response ?? $log->response,
            'code' => is_array($response) ? ($response['responseCode'] ?? null) : null,
            'message' => is_array($response) ? ($response['responseMessage'] ?? null) : null,
            'status' => $log->accepted && ($body['latestTransactionStatus'] ?? null) === '00' && $amountsMatch
                && ($response['responseCode'] ?? null) === YoduGateway::CALLBACK_OK['code'] ? 'PASSED' : 'FAILED',
            'note' => "Callback diterima {$log->created_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s')} WIB (log #{$log->id}). "
                ."latestTransactionStatus {$money('latestTransactionStatus')} · amount {$money('amount')} · payAmount {$money('payAmount')} · "
                ."feeAmount {$money('feeAmount')} · mdrAmount {$money('mdrAmount')} · handlingFee {$money('handlingFee')}. "
                .($amountsMatch ? 'Nominal sesuai: payAmount = amount QR, feeAmount = fee QR, amount = payAmount + fee − MDR − handlingFee.'
                    : 'Nominal TIDAK sesuai dengan QR A (cek payAmount, feeAmount, dan amount bersih).'),
        ];
    }

    /** @return array<string, mixed> */
    private function qrBody(): array
    {
        return $this->gateway->qrRequestBody(YoduClient::uniqueRef('UJI'), (int) $this->option('amount'));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function send(string $path, array $body, ?string $token = null): array
    {
        return $this->withCode($this->client->probe($path, $body, $token));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function withCode(array $result): array
    {
        $response = is_array($result['response']) ? $result['response'] : [];

        return $result + ['code' => $response['responseCode'] ?? null, 'message' => $response['responseMessage'] ?? null];
    }

    /** @param  array<string, mixed>  $result */
    private function successStatus(array $result): string
    {
        return is_array($result['response']) && YoduClient::isSuccess($result['response']) ? 'PASSED' : 'FAILED';
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, string>|null
     */
    private function qrState(array $result): ?array
    {
        $response = is_array($result['response']) ? $result['response'] : [];
        if (! YoduClient::isSuccess($response) || empty($response['responseData']['qrContent'])) {
            return null;
        }

        return [
            'partner_reference_no' => (string) json_decode($result['body'], true)['partnerReferenceNo'],
            'reference_no' => (string) ($response['referenceNo'] ?? ''),
            'qr_reff_id' => (string) ($response['responseData']['qrReffId'] ?? ''),
            'qr_content' => (string) $response['responseData']['qrContent'],
            'created_at' => now()->toIso8601String(),
        ];
    }

    /** Payment sementara (tidak disimpan) supaya body cancel / cek status memakai builder yang sama dengan aplikasi. */
    private function payment(array $qr): Payment
    {
        return (new Payment)->forceFill([
            'merchant_ref' => $qr['partner_reference_no'],
            'gateway_ref' => $qr['reference_no'],
            'gateway_qr_id' => $qr['qr_reff_id'],
            'created_at' => Carbon::parse($qr['created_at']),
        ]);
    }

    /** @return array<string, mixed> */
    private function loadState(): array
    {
        $json = Storage::disk('local')->get(self::DIRECTORY.'/state.json');

        return $json ? (json_decode($json, true) ?: []) : [];
    }

    private function saveState(): void
    {
        Storage::disk('local')->put(self::DIRECTORY.'/state.json', (string) json_encode($this->state, JSON_PRETTY_PRINT));
    }

    private function report(): string
    {
        $out = ['# Test Case YODU Open API Acquirer: KOLEKSI (production)', '',
            'Tanggal pengujian: '.now('Asia/Jakarta')->format('Y-m-d H:i').' WIB', 'Base URL: '.config('yodu.base_url'), '',
            'Client Secret (X-CLIENT-KEY) dan access token disamarkan.', ''];

        foreach (self::CASES as $id => [$endpoint, $scenario, $expected]) {
            $r = $this->state['results'][$id] ?? ['status' => 'BELUM DIJALANKAN'];

            $out[] = "## {$id} · {$endpoint}";
            $out[] = '';
            $out[] = "Skenario: {$scenario}  ";
            $out[] = "Expected: {$expected}  ";
            $out[] = "Status: **{$r['status']}**".(isset($r['code']) ? " (responseCode {$r['code']})" : '')
                .(isset($r['tested_at']) ? " · {$r['tested_at']} WIB" : '');
            $out[] = '';

            foreach (['note', 'skipped'] as $key) {
                if (isset($r[$key])) {
                    $out[] = $r[$key];
                    $out[] = '';
                }
            }
            if (isset($r['error'])) {
                $out[] = 'Error: '.$r['error'];
                $out[] = '';
            }
            if (! isset($r['url'])) {
                continue;
            }

            $out[] = '```';
            $out[] = "POST {$r['url']}";
            $out[] = '';
            $out[] = 'Header Request:';
            $out[] = self::pretty(self::maskHeaders($r['headers']));
            $out[] = '';
            $out[] = 'Request:';
            $out[] = self::pretty($r['body']);
            $out[] = '';
            $out[] = "Respon (HTTP {$r['http']}):";
            $out[] = self::pretty(self::maskToken($r['response']));
            $out[] = '```';
            $out[] = '';
        }

        return implode("\n", $out)."\n";
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private static function maskHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            $headers[$name] = match (strtolower($name)) {
                'x-client-key' => self::mask($value),
                'authorization' => 'Bearer '.self::mask(Str::after($value, 'Bearer ')),
                default => $value,
            };
        }

        return $headers;
    }

    private static function maskToken(mixed $response): mixed
    {
        if (is_array($response) && isset($response['accessToken'])) {
            $response['accessToken'] = self::mask((string) $response['accessToken']);
        }

        return $response;
    }

    private static function mask(string $value): string
    {
        return strlen($value) <= 8 ? '****' : substr($value, 0, 4).'****'.substr($value, -4);
    }

    /** JSON rapi; string JSON di-decode dulu. */
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
