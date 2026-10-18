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
        Schema::table('guide_articles', function (Blueprint $table) {
            // Jumlah dibuka di website (halaman detail), untuk widget "Terpopuler"
            $table->unsignedBigInteger('views')->default(0)->after('published_at');

            // Query website: artikel published terpopuler
            $table->index(['status', 'views']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guide_articles', function (Blueprint $table) {
            $table->dropIndex(['status', 'views']);
            $table->dropColumn('views');
        });
    }
};
