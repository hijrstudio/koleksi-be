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
        // Kegiatan: video dokumentasi kegiatan (tanpa kategori). Beda dengan video edukasi (guide_videos).
        Schema::create('event_videos', function (Blueprint $table) {
            $table->id();
            $table->json('title'); // {"id": "...", "en": "..."}
            $table->string('thumbnail')->nullable(); // path upload; kosong = pakai thumbnail YouTube
            // Hanya ID video (11 karakter), bukan URL. FE: https://www.youtube-nocookie.com/embed/{youtube_id}
            $table->string('youtube_id', 11)->index();
            $table->string('status', 20)->default('draft'); // draft | published
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Query website: video published, terbaru dulu
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_videos');
    }
};
