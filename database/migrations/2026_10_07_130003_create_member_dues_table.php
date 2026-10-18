<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu baris = tagihan iuran 1 member untuk 1 periode.
        // Detail transaksi gateway (YODU) nanti ada di tabel `payments` (fase 2).
        Schema::create('member_dues', function (Blueprint $t) {
            $t->id();
            $t->string('invoice_number', 32)->unique();       // IUR-2627-0007
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->foreignId('membership_period_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount');                   // Rupiah, tanpa desimal

            // unpaid : belum lunas
            // paid   : lunas (via payment gateway, atau dicatat manual oleh admin)
            // waived : dibebaskan (mis. member lama saat migrasi, pengurus)
            $t->enum('status', ['unpaid', 'paid', 'waived'])->default('unpaid')->index();

            $t->enum('payment_method', ['online', 'cash', 'transfer', 'waived'])->nullable(); // online = via payment gateway
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('recorded_by')->nullable()             // admin, kalau manual/waived
                ->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->unique(['member_id', 'membership_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_dues');
    }
};
