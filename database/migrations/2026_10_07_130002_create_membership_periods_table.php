<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_periods', function (Blueprint $t) {
            $t->id();
            $t->string('name', 9)->unique();          // "2026-2027"
            $t->date('start_date');
            $t->date('end_date');
            $t->unsignedBigInteger('fee')->default(0); // nominal iuran (Rupiah, tanpa desimal)
            $t->date('renewal_opens_at')->nullable();  // daftar ulang mulai dibuka
            $t->timestamps();

            $t->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_periods');
    }
};
