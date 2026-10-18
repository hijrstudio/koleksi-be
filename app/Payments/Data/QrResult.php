<?php

namespace App\Payments\Data;

use Illuminate\Support\Carbon;

final class QrResult
{
    public function __construct(
        public readonly string $qrString,
        public readonly Carbon $expiredAt,
        public readonly ?string $gatewayRef = null,   // YODU referenceNo
        public readonly ?string $gatewayQrId = null,  // YODU qrReffId
        public readonly array $request = [],
        public readonly array $response = [],
    ) {}
}
