<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Jawaban yang kita kirim ke gateway (bukti uji fungsional YODU 18.18/18.19 & debug)
        Schema::table('payment_notifications', function (Blueprint $table) {
            $table->unsignedSmallInteger('response_status')->nullable()->after('body');
            $table->text('response')->nullable()->after('response_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_notifications', function (Blueprint $table) {
            $table->dropColumn(['response_status', 'response']);
        });
    }
};
