<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pendaftaran member baru lewat website (form /daftar).
 *
 * Pendaftar disimpan langsung di `members` (satu sumber data anggota), dibedakan dengan registration_status:
 *   unverified → e-mail belum diverifikasi
 *   pending    → e-mail terverifikasi, menunggu review pengurus di CMS
 *   approved   → disetujui (anggota resmi; aktif setelah iuran pertama lunas). Default untuk data lama / import.
 *   rejected   → ditolak pengurus
 *
 * No. lambung baru diberikan saat disetujui → kolomnya nullable (unique tetap berlaku untuk nilai yang terisi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $t) {
            $t->unsignedInteger('no_lambung')->nullable()->change();

            $t->string('registration_status', 20)->default('approved')->after('status_mode');
            $t->timestamp('registered_at')->nullable()->after('registration_status');   // submit form daftar
            $t->string('registration_ip', 45)->nullable()->after('registered_at');
            $t->string('heard_from', 60)->nullable()->after('registration_ip');          // "Darimana Anda tahu tentang kami?"

            $t->foreignId('reviewed_by')->nullable()->after('heard_from')->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $t->string('review_note', 500)->nullable()->after('reviewed_at');            // alasan penolakan

            // Antrian review di CMS: filter status, urut tanggal daftar
            $t->index(['registration_status', 'registered_at']);
        });
    }

    public function down(): void
    {
        // Pendaftar yang belum disetujui tidak punya no. lambung → tolak supaya datanya tidak hilang diam-diam
        if (($pending = DB::table('members')->whereNull('no_lambung')->count()) > 0) {
            throw new RuntimeException("{$pending} pendaftar belum punya no. lambung. Setujui atau hapus dulu sebelum rollback.");
        }

        Schema::table('members', function (Blueprint $t) {
            $t->dropIndex(['registration_status', 'registered_at']);
            $t->dropConstrainedForeignId('reviewed_by');
            $t->dropColumn(['registration_status', 'registered_at', 'registration_ip', 'heard_from', 'reviewed_at', 'review_note']);
        });

        Schema::table('members', function (Blueprint $t) {
            $t->unsignedInteger('no_lambung')->nullable(false)->change();
        });
    }
};
