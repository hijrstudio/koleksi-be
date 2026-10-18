<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu baris = satu QRIS Dynamic yang dibuat di gateway (YODU).
        // QR kedaluwarsa → member klik "Buat QRIS baru" → baris baru.
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();

            $t->string('gateway', 20)->default('yodu')->index();    // yodu | fake
            $t->string('merchant_ref', 64)->unique();                // YODU partnerReferenceNo (dari kita)
            $t->string('gateway_ref', 64)->nullable()->index();      // YODU referenceNo (cek status & cancel)
            $t->string('gateway_qr_id', 50)->nullable();             // YODU qrReffId (cancel)

            $t->unsignedBigInteger('amount');                        // total tagihan = YODU payAmount
            $t->unsignedBigInteger('net_amount')->nullable();        // YODU amount (setelah potongan)
            $t->unsignedBigInteger('mdr_amount')->nullable();        // YODU mdrAmount
            $t->unsignedBigInteger('handling_fee')->nullable();      // YODU handlingFee

            // pending | success | failed | expired | cancelled | refunded
            $t->string('status', 12)->default('pending')->index();

            $t->text('qr_string')->nullable();                       // YODU qrContent → dirender jadi QR di FE
            $t->string('payment_channel', 50)->nullable();           // YODU issuerName: GoPay, OVO, BCA ...
            $t->timestamp('expired_at')->nullable()->index();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('last_checked_at')->nullable();            // throttle cek status ke gateway

            $t->json('request_payload')->nullable();
            $t->json('response_payload')->nullable();
            $t->json('last_callback')->nullable();
            $t->text('failure_reason')->nullable();
            $t->timestamps();

            $t->index(['member_id', 'status']);
        });

        // Satu pembayaran bisa melunasi beberapa tagihan sekaligus (billing.items di FE).
        Schema::create('payment_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('member_due_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('amount');
            $t->timestamps();

            $t->unique(['payment_id', 'member_due_id']);
        });

        // Log mentah setiap callback gateway — untuk audit & debug.
        Schema::create('payment_notifications', function (Blueprint $t) {
            $t->id();
            $t->string('gateway', 20)->index();
            $t->string('merchant_ref', 64)->nullable()->index();
            $t->string('gateway_status', 10)->nullable();
            $t->boolean('accepted')->default(false);                 // lolos verifikasi awal (IP)
            $t->boolean('processed')->default(false);                // terkonfirmasi & diterapkan
            $t->string('ip', 45)->nullable();
            $t->json('headers')->nullable();
            $t->longText('body');
            $t->text('error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_notifications');
        Schema::dropIfExists('payment_items');
        Schema::dropIfExists('payments');
    }
};
