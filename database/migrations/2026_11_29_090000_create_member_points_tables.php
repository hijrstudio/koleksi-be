<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Poin papan peringkat forum (lihat config/points.php & PointService).
 *
 *   member_point_logs   : satu baris per poin yang didapat. unique (member, action, ref_key) → aktivitas yang sama
 *                         tidak bisa memberi poin dua kali (request ganda, refresh, dua tab). Ditarik = revoked_at.
 *   member_point_totals : angka jadi per periode ('all' / '2026-11') untuk papan peringkat, diubah atomik
 *                         bersama log. Papan peringkat cukup membaca index (period, points).
 *
 * Nama index ditulis eksplisit (batas 64 karakter MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_point_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->string('action', 20);
            $t->smallInteger('points');
            $t->string('ref_key', 120);      // mis. comment:123, read:news:45, menu:berita:2026-11-26
            $t->date('earned_on');           // tanggal WIB (batas harian & periode bulanan)
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('created_at')->nullable();

            $t->unique(['member_id', 'action', 'ref_key'], 'member_point_logs_member_action_ref_unique');
            $t->index(['member_id', 'action', 'earned_on'], 'member_point_logs_member_action_day_index');
            $t->index(['action', 'ref_key'], 'member_point_logs_action_ref_index');
        });

        Schema::create('member_point_totals', function (Blueprint $t) {
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->string('period', 7);         // 'all' | 'YYYY-MM'
            $t->integer('points')->default(0);
            $t->timestamp('reached_at')->nullable();   // terakhir bertambah: seri poin → yang lebih dulu di atas
            $t->timestamp('updated_at')->nullable();

            $t->primary(['member_id', 'period']);
            $t->index(['period', 'points', 'reached_at'], 'member_point_totals_period_points_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_point_totals');
        Schema::dropIfExists('member_point_logs');
    }
};
