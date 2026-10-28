<?php

namespace App\Payments\Gateways;

use App\Models\Payment;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Data\QrResult;
use App\Payments\Data\TxStatus;
use App\Payments\Yodu\YoduClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * YODU Open API — QRIS Acquirer MPM, QR Dynamic (qrType=03).
 * Spec: Security v1.3 · QRIS ACQ MPM v1.0 · Check Status v1.0 · Start Integration Guide v1.1
 */
class YoduGateway implements PaymentGateway
{
    public const PATH_GENERATE = '/openapi/v1.0/qr-acq/mpm/generate';

    public const PATH_CANCEL = '/openapi/v1.0/qr-acq/mpm/cancelqr';

    public const PATH_STATUS = '/openapi/v1.0/qr-acq/paystatus';

    /** Jawaban callback (Payment Notification, service code 52) sesuai uji fungsional YODU 18.18/18.19. */
    public const CALLBACK_OK = ['code' => '2005200', 'message' => 'Successful'];

    public const CALLBACK_REJECTED = ['code' => '4015200', 'message' => 'Unauthorized'];

    private const RC_NOT_FOUND = '5008203';

    private ?YoduClient $client;

    public function __construct(private array $config, ?YoduClient $client = null)
    {
        $this->client = $client;
    }

    public function name(): string
    {
        return 'yodu';
    }

    /**
     * Body Generate QR (dipakai aplikasi & yodu:uji-fungsional).
     *
     * @return array<string, mixed>
     */
    public function qrRequestBody(string $merchantRef, int $amount): array
    {
        return [
            'partnerReferenceNo' => $merchantRef,
            'merchantId' => $this->config['merchant_id'],
            'storeId' => $this->config['store_id'],
            'terminalId' => $this->config['terminal_id'],
            'qrType' => '03',
            'amount' => ['value' => self::money($amount), 'currency' => 'IDR'],
            'withFee' => '0',                                     // member bayar pas, tanpa tip
            'feeType' => '',
            'feeAmount' => ['value' => '', 'currency' => 'IDR'],
            'expiryPeriod' => (string) $this->config['qr_expiry_seconds'],
            'additionalInfo' => (object) [],
        ];
    }

    /**
     * Body cek status (dipakai aplikasi & yodu:uji-fungsional).
     *
     * @return array<string, mixed>
     */
    public function statusRequestBody(Payment $payment): array
    {
        return [
            'partnerReferenceNo' => YoduClient::uniqueRef('S'),
            'originalReferenceNo' => $payment->gateway_ref ?? '',
            'originalPartnerReferenceNo' => $payment->merchant_ref,
            'originalRequestDate' => $payment->created_at->copy()->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i:sP'),
            'serviceCode' => '47',
            'merchantId' => $this->config['merchant_id'],
            'storeId' => $this->config['store_id'],
            'terminalId' => $this->config['terminal_id'],
            'additionalInfo' => (object) [],
        ];
    }

    public function createQr(Payment $payment): QrResult
    {
        $body = $this->qrRequestBody($payment->merchant_ref, $payment->amount);

        $res = $this->client()->post(self::PATH_GENERATE, $body);
        $json = $res['body'];
        $data = $json['responseData'] ?? [];

        if (! YoduClient::isSuccess($json) || empty($data['qrContent'])) {
            throw new RuntimeException(sprintf('YODU generate QR gagal [%s] %s',
                $json['responseCode'] ?? 'HTTP '.$res['http'], $json['responseMessage'] ?? ''));
        }

        return new QrResult(
            qrString: $data['qrContent'],
            expiredAt: self::time($data['validityPeriod'] ?? null)
                ?? now()->addSeconds((int) $this->config['qr_expiry_seconds']),
            gatewayRef: $json['referenceNo'] ?? null,
            gatewayQrId: $data['qrReffId'] ?? null,
            request: $body,
            response: $json,
        );
    }

    public function cancelQr(Payment $payment, string $reason = 'Diganti QR baru'): bool
    {
        if (! $payment->gateway_ref || ! $payment->gateway_qr_id) {
            return false;
        }

        $res = $this->client()->post(self::PATH_CANCEL, [
            'partnerReferenceNo' => YoduClient::uniqueRef('C'),
            'originalReferenceNo' => $payment->gateway_ref,
            'qrReffId' => $payment->gateway_qr_id,
            'cancelReason' => $reason,
            'additionalInfo' => (object) [],
        ]);

        return YoduClient::isSuccess($res['body']);
    }

    public function checkStatus(Payment $payment): ?TxStatus
    {
        try {
            $res = $this->client()->post(self::PATH_STATUS, $this->statusRequestBody($payment));
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $json = $res['body'];

        // Spec: status ada di responseData. Sandbox YODU menjawab datar (seperti callback) → terima dua-duanya.
        $data = isset($json['responseData']['latestTransactionStatus']) ? $json['responseData'] : $json;

        // Jejak jawaban YODU per cek status — tanpa ini "masih pending" tidak bisa dibedakan dari "ditolak YODU"
        Log::info('YODU cek status', [
            'ref' => $payment->merchant_ref,
            'http' => $res['http'],
            'code' => $json['responseCode'] ?? null,
            'message' => $json['responseMessage'] ?? null,
            'tx_status' => $data['latestTransactionStatus'] ?? null,
            'body' => Str::limit((string) json_encode($json), 3000),
        ]);

        if (! YoduClient::isSuccess($json)) {
            // Transaction Not Found = belum ada pembayaran
            return ($json['responseCode'] ?? '') === self::RC_NOT_FOUND
                ? new TxStatus($payment->merchant_ref, TxStatus::PENDING, rawCode: '07', raw: $json)
                : null;
        }

        // merchant_ref dipaksa dari payment kita (bukan dari jawaban), supaya tidak bisa "salah sasaran"
        return $this->toStatus(
            ['originalPartnerReferenceNo' => $payment->merchant_ref] + (is_array($data) ? $data : []),
            fromCallback: false,
        );
    }

    /**
     * Spec YODU belum menjelaskan cara verifikasi signature callback.
     * Lapis 1: whitelist IP (YODU_CALLBACK_IPS, kalau diberi YODU).
     * Lapis 2: YODU mengirim client secret kita di header X-CLIENT-KEY → wajib cocok.
     * Lapis 3 (di PaymentService): SUCCESS dari callback SELALU dikonfirmasi ke API cek status.
     */
    public function verifyCallback(Request $request): bool
    {
        $ips = $this->config['callback_ips'] ?? [];
        if ($ips !== [] && ! in_array($request->ip(), $ips, true)) {
            return false;
        }

        $secret = (string) ($this->config['client_secret'] ?? '');

        return $secret === '' || hash_equals($secret, (string) $request->header('X-CLIENT-KEY'));
    }

    public function parseCallback(Request $request): TxStatus
    {
        $d = $request->json()->all();
        $d['originalPartnerReferenceNo'] = $this->callbackMerchantRef($d);

        return $this->toStatus($d, fromCallback: true);
    }

    /**
     * Sandbox YODU mengisi originalPartnerReferenceNo dengan referenceNo miliknya sendiri (bukan nomor kita),
     * jadi payment juga dicari lewat referenceNo (gateway_ref) & qrReffId (gateway_qr_id) dari generate QR.
     */
    private function callbackMerchantRef(array $d): string
    {
        $ref = (string) ($d['originalPartnerReferenceNo'] ?? '');

        $payment = Payment::where('gateway', $this->name())
            ->where(function ($q) use ($d, $ref) {
                $q->where('merchant_ref', $ref);
                if (filled($d['originalReferenceNo'] ?? null)) {
                    $q->orWhere('gateway_ref', (string) $d['originalReferenceNo']);
                }
                if (filled($d['qrReffId'] ?? null)) {
                    $q->orWhere('gateway_qr_id', (string) $d['qrReffId']);
                }
            })
            ->first();

        return $payment->merchant_ref ?? $ref;
    }

    /**
     * Notifikasi diterima (status apa pun, termasuk gagal "06") = 200 + 2005200 "Successful".
     * Ditolak verifikasi (IP / X-CLIENT-KEY / format) = 401 + 4015200 "Unauthorized".
     */
    public function callbackResponse(bool $ok, array $payload = []): Response
    {
        $now = YoduClient::timestamp();
        $result = $ok ? self::CALLBACK_OK : self::CALLBACK_REJECTED;

        return response()->json([
            'responseCode' => $result['code'],
            'responseMessage' => $result['message'],
            'partnerReferenceNo' => (string) ($payload['partnerReferenceNo'] ?? ''),
            'referenceNo' => YoduClient::uniqueRef('CB'),
            'originalReferenceNo' => (string) ($payload['originalReferenceNo'] ?? ''),
            'originalPartnerReferenceNo' => (string) ($payload['originalPartnerReferenceNo'] ?? ''),
            'receivedTime' => $now,
            'additionalInfo' => (object) [],
        ], $ok ? 200 : 401, ['X-TIMESTAMP' => $now]);
    }

    // ------------------------------------------------------------------

    /** Dibuat saat pertama dipakai — kredensial yang belum lengkap tidak merusak request lain. */
    private function client(): YoduClient
    {
        return $this->client ??= YoduClient::make();
    }

    private function toStatus(array $d, bool $fromCallback): TxStatus
    {
        $code = (string) ($d['latestTransactionStatus'] ?? '');

        // 00 Success · 01 Initiated · 02 Paying · 03 Pending · 04 Refunded · 05 Canceled · 06 Failed · 07 Not found
        $status = match ($code) {
            '00' => TxStatus::SUCCESS,
            '04' => TxStatus::REFUNDED,
            '05' => TxStatus::CANCELLED,
            '06' => TxStatus::FAILED,
            default => TxStatus::PENDING,
        };

        return new TxStatus(
            merchantRef: (string) ($d['originalPartnerReferenceNo'] ?? ''),
            status: $status,
            payAmount: self::int($d['payAmount']['value'] ?? null),
            netAmount: self::int($d['amount']['value'] ?? null),
            mdrAmount: self::int($d['mdrAmount']['value'] ?? null),
            handlingFee: self::int($d['handlingFee']['value'] ?? null),
            gatewayRef: isset($d['originalReferenceNo']) ? (string) $d['originalReferenceNo'] : null,
            channel: isset($d['issuerName']) ? mb_substr((string) $d['issuerName'], 0, 50) : null,
            paidAt: self::time($d['paidTime'] ?? null),
            rawCode: $code,
            raw: $d,
            fromCallback: $fromCallback,
        );
    }

    private static function money(int $rupiah): string
    {
        return number_format($rupiah, 2, '.', '');   // 150000 → "150000.00"
    }

    private static function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) round((float) $v) : null;
    }

    private static function time(mixed $v): ?Carbon
    {
        try {
            return is_string($v) && $v !== '' ? Carbon::parse($v) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
