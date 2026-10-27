<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Berita EV = tautan ke berita di media lain (bukan artikel KOLEKSI): judul + ringkasan + link ke sumber,
     * tanpa konten. Kolom sumber hanya diisi untuk kategori ev_news (App\Enums\NewsCategory::isExternal()).
     */
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->json('summary')->nullable()->after('title');            // {"id": "...", "en": "..."}
            $table->string('source_url', 500)->nullable()->after('image');  // link berita asli (http/https)
            $table->string('source_name', 150)->nullable()->after('source_url');   // nama media, mis. "Kompas.com"
            $table->string('source_author', 150)->nullable()->after('source_name'); // penulis di media tsb (teks bebas)
            $table->date('source_published_on')->nullable()->after('source_author'); // tanggal berita di media sumber
        });

        // Berita EV tidak punya konten; kategori lain tetap wajib (divalidasi di NewsRequest)
        Schema::table('news', function (Blueprint $table) {
            $table->json('content')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Berita EV tanpa konten diberi konten kosong dulu supaya kolom bisa kembali NOT NULL
        DB::table('news')->whereNull('content')->update(['content' => json_encode(['id' => '', 'en' => null])]);

        Schema::table('news', function (Blueprint $table) {
            $table->json('content')->nullable(false)->change();
            $table->dropColumn(['summary', 'source_url', 'source_name', 'source_author', 'source_published_on']);
        });
    }
};
