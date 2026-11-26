<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laporan transaksi CMS: hasil "Cek ke YODU" (rekonsiliasi status & nominal sistem kita vs YODU) disimpan per
 * pembayaran, plus index untuk filter tanggal bayar. Nama index ditulis eksplisit (batas 64 karakter MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->timestamp('reconciled_at')->nullable()->after('last_checked_at');
            $t->string('reconcile_status', 12)->nullable()->after('reconciled_at');   // match | mismatch | error
            $t->string('reconcile_note', 255)->nullable()->after('reconcile_status');
            $t->foreignId('reconciled_by')->nullable()->after('reconcile_note')->constrained('users')->nullOnDelete();

            $t->index(['status', 'paid_at'], 'payments_status_paid_at_index');
            $t->index(['created_at'], 'payments_created_at_index');
        });

        Schema::table('member_dues', function (Blueprint $t) {
            $t->index(['status', 'paid_at'], 'member_dues_status_paid_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('member_dues', function (Blueprint $t) {
            $t->dropIndex('member_dues_status_paid_at_index');
        });

        Schema::table('payments', function (Blueprint $t) {
            $t->dropIndex('payments_status_paid_at_index');
            $t->dropIndex('payments_created_at_index');
            $t->dropConstrainedForeignId('reconciled_by');
            $t->dropColumn(['reconciled_at', 'reconcile_status', 'reconcile_note']);
        });
    }
};
