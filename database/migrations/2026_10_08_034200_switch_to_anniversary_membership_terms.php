<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Periode keanggotaan tidak lagi global (Agustus–Juli), tapi per member:
 * 1 tahun dihitung dari tanggal bergabung (ulang tahun keanggotaan).
 *
 *   bergabung 10 Feb 2026 → periode "2026-2027" = 10 Feb 2026 – 9 Feb 2027
 *                         → daftar ulang Feb 2027 untuk periode "2027-2028"
 *
 * Tagihan (member_dues) kini menyimpan rentang periodenya sendiri.
 */
return new class extends Migration
{
    private const IMPORT_NOTE = 'Migrasi data awal (import Excel)';

    public function up(): void
    {
        // Tagihan lama hanya berisi pembebasan hasil import (periode global) → dibuat ulang lewat
        // `php artisan members:import ... --waive-current`. Tolak kalau ada data lain supaya tidak hilang.
        $other = DB::table('member_dues')
            ->where(fn ($q) => $q->where('status', '!=', 'waived')->orWhere('notes', '!=', self::IMPORT_NOTE)->orWhereNull('notes'))
            ->count();

        if ($other > 0) {
            throw new RuntimeException("member_dues berisi {$other} tagihan selain hasil import. Migrasi data periode perlu ditangani manual.");
        }

        DB::table('member_dues')->delete();

        Schema::table('member_dues', function (Blueprint $t) {
            $t->string('period_label', 9)->after('membership_period_id');   // "2026-2027"
            $t->date('period_start')->after('period_label');
            $t->date('period_end')->after('period_start')->index();
            $t->unique(['member_id', 'period_start']);
        });

        Schema::table('member_dues', function (Blueprint $t) {
            $t->dropForeign(['membership_period_id']);
            $t->dropUnique(['member_id', 'membership_period_id']);
            $t->dropColumn('membership_period_id');
        });

        Schema::dropIfExists('membership_periods');

        Schema::table('members', function (Blueprint $t) {
            // Akhir periode terakhir yang sudah lunas. Daftar ulang jatuh tempo = paid_until + 1 hari.
            $t->date('paid_until')->nullable()->after('status_mode')->index();
        });

        // Catatan pengingat yang sudah dikirim (anti dobel kirim)
        Schema::create('member_renewal_reminders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->date('period_end');                 // periode yang diingatkan
            $t->string('stage', 20);                // "30", "7", "0" (hari sebelum jatuh tempo) | "expired"
            $t->json('channels');                   // ["database","mail"]
            $t->timestamp('sent_at')->useCurrent();

            $t->unique(['member_id', 'period_end', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_renewal_reminders');

        Schema::table('members', function (Blueprint $t) {
            $t->dropColumn('paid_until');
        });

        Schema::create('membership_periods', function (Blueprint $t) {
            $t->id();
            $t->string('name', 9)->unique();
            $t->date('start_date');
            $t->date('end_date');
            $t->unsignedBigInteger('fee')->default(0);
            $t->date('renewal_opens_at')->nullable();
            $t->timestamps();

            $t->index(['start_date', 'end_date']);
        });

        DB::table('member_dues')->delete();

        Schema::table('member_dues', function (Blueprint $t) {
            $t->foreignId('membership_period_id')->after('member_id')->constrained()->restrictOnDelete();
            $t->unique(['member_id', 'membership_period_id']);
        });

        Schema::table('member_dues', function (Blueprint $t) {
            $t->dropUnique(['member_id', 'period_start']);
            $t->dropColumn(['period_label', 'period_start', 'period_end']);
        });
    }
};
