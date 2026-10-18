<?php

namespace App\Payments\Data;

use Illuminate\Support\Carbon;

/** Status transaksi dalam format standar (dari callback atau cek status). */
final class TxStatus
{
    public const SUCCESS = 'success';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const REFUNDED = 'refunded';

    public function __construct(
        public readonly string $merchantRef,
        public readonly string $status,
        public readonly ?int $payAmount = null,       // dibayar member
        public readonly ?int $netAmount = null,       // diterima KOLEKSI setelah potongan
        public readonly ?int $mdrAmount = null,
        public readonly ?int $handlingFee = null,
        public readonly ?string $gatewayRef = null,
        public readonly ?string $channel = null,      // GoPay, OVO, BCA ...
        public readonly ?Carbon $paidAt = null,
        public readonly ?string $rawCode = null,      // latestTransactionStatus asli
        public readonly array $raw = [],
        public readonly bool $fromCallback = false,   // true = SUCCESS wajib dikonfirmasi ulang ke cek status
    ) {}
}
