<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alur pendaftaran baru (tanpa review pengurus): daftar → verifikasi e-mail → bayar iuran pertama → aktif.
 *
 * - members.payment_due_at: batas bayar pendaftar berstatus awaiting_payment; lewat batas tanpa pembayaran →
 *   dihapus members:prune-registrations. Diperpanjang saat link bayar dikirim ulang.
 * - member_vehicles.vin: No. rangka sekarang opsional (unique tetap berlaku untuk yang terisi).
 * - settings: pengaturan sederhana yang diubah pengurus di CMS (mis. nomor awal No. lambung anggota baru).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $t) {
            $t->timestamp('payment_due_at')->nullable()->after('registered_at');
            $t->index(['registration_status', 'payment_due_at']);
        });

        Schema::table('member_vehicles', function (Blueprint $t) {
            $t->string('vin', 17)->nullable()->change();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 100)->primary();
            $t->text('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');

        Schema::table('member_vehicles', function (Blueprint $t) {
            $t->string('vin', 17)->nullable(false)->change();
        });

        Schema::table('members', function (Blueprint $t) {
            $t->dropIndex(['registration_status', 'payment_due_at']);
            $t->dropColumn('payment_due_at');
        });
    }
};
