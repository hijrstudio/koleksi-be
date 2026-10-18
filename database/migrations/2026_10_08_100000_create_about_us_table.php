<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tabel singleton: hanya berisi 1 row (id = AboutUs::SINGLETON_ID).
     * Kolom json berformat {"id": "...", "en": "..."}; kolom rich text berisi HTML yang sudah disanitasi.
     */
    public function up(): void
    {
        Schema::create('about_us', function (Blueprint $table) {
            $table->id();
            $table->json('summary'); // rich text
            $table->string('image')->nullable();
            $table->json('profil_organisasi')->nullable(); // rich text
            $table->json('visi')->nullable(); // plain text
            $table->json('misi')->nullable(); // rich text
            $table->string('aturan_ad_art')->nullable(); // pdf
            $table->json('syarat_anggota')->nullable(); // rich text
            $table->json('daftar_ulang_anggota')->nullable(); // rich text
            $table->string('sponsor_doc')->nullable(); // pdf / doc / docx
            $table->string('mitra_doc')->nullable(); // pdf / doc / docx
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('about_us');
    }
};
