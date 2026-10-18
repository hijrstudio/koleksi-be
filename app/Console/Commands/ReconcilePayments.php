<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Payments\PaymentService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Jaring pengaman kalau callback gateway hilang DAN member sudah menutup halaman:
 * QR pending yang sudah lewat masa berlaku → cek status ke gateway → success / expired.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Cek status QRIS pending yang sudah lewat masa berlaku';

    public function handle(PaymentService $payments): int
    {
        $done = 0;

        Payment::pending()
            ->where('expired_at', '<', now())
            ->chunkById(50, function ($rows) use ($payments, &$done) {
                foreach ($rows as $payment) {
                    try {
                        $payments->refresh($payment, force: true);
                        $done++;
                    } catch (Throwable $e) {
                        report($e);
                        $this->warn("{$payment->merchant_ref}: {$e->getMessage()}");
                    }
                }
            });

        $this->info("{$done} pembayaran direkonsiliasi.");

        return self::SUCCESS;
    }
}
