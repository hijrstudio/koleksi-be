<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('event_categories', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->after('name')->index(); // draft | published
        });

        // Kategori yang sudah ada sebelumnya tampil tanpa status, jadi dianggap published
        // supaya event di dalamnya tidak tiba-tiba hilang dari website.
        DB::table('event_categories')->update(['status' => 'published']);

        Schema::table('events', function (Blueprint $table) {
            $table->string('image')->nullable()->after('slug'); // path gambar / poster event
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('image');
        });

        Schema::table('event_categories', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
