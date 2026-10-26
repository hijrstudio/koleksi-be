<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * payment_method "amnesty": tagihan dibebaskan karena pemutihan tunggakan (members:waive-arrears).
     * Hanya menambah pilihan enum — data yang ada tidak berubah.
     */
    public function up(): void
    {
        Schema::table('member_dues', function (Blueprint $t) {
            $t->enum('payment_method', ['online', 'cash', 'transfer', 'waived', 'amnesty'])->nullable()->change();
        });
    }

    public function down(): void
    {
        // Pemutihan dikembalikan ke "waived" dulu supaya enum lama bisa dipasang lagi tanpa kehilangan data
        DB::table('member_dues')->where('payment_method', 'amnesty')->update(['payment_method' => 'waived']);

        Schema::table('member_dues', function (Blueprint $t) {
            $t->enum('payment_method', ['online', 'cash', 'transfer', 'waived'])->nullable()->change();
        });
    }
};
