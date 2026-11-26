<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tujuan pembayaran QRIS: registration (biaya pendaftaran anggota baru) | dues (iuran tahunan / perpanjangan).
 * Diisi PaymentService saat QR dibuat. Data lama: pendaftar belum punya No. lambung, jadi No. referensinya
 * berawalan "KLKR" (lihat PaymentService::checkoutLocked) → pendaftaran; selain itu iuran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->string('purpose', 12)->default('dues')->after('gateway');
            $t->index(['purpose', 'status'], 'payments_purpose_status_index');
        });

        DB::table('payments')->where('merchant_ref', 'like', 'KLKR%')->update(['purpose' => 'registration']);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropIndex('payments_purpose_status_index');
            $t->dropColumn('purpose');
        });
    }
};
