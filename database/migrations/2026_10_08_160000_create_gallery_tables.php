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
        // Galeri foto: album
        Schema::create('gallery_albums', function (Blueprint $table) {
            $table->id();
            $table->string('image'); // path cover album di disk public
            $table->json('title'); // {"id": "...", "en": "..."}
            $table->date('gallery_date'); // tanggal kegiatan / galeri
            $table->string('status', 20)->default('draft'); // draft | published
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Query website: album published, terbaru (tanggal galeri) dulu
            $table->index(['status', 'gallery_date']);
        });

        // Galeri foto: foto di dalam album (repeater)
        Schema::create('gallery_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_album_id')->constrained()->cascadeOnDelete();
            // unik: satu file hanya milik satu foto, jadi aman dihapus saat foto/album dihapus
            $table->string('image')->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['gallery_album_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gallery_photos');
        Schema::dropIfExists('gallery_albums');
    }
};
