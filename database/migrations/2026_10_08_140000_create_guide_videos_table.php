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
        // Panduan Praktis: video edukasi (kategori memakai guide_categories)
        Schema::create('guide_videos', function (Blueprint $table) {
            $table->id();
            // restrict: kategori yang masih punya video tidak bisa dihapus
            $table->foreignId('guide_category_id')->constrained()->restrictOnDelete();
            $table->json('title'); // {"id": "...", "en": "..."}
            $table->string('thumbnail')->nullable(); // path upload; kosong = pakai thumbnail YouTube
            // Hanya ID video (11 karakter), bukan URL. FE: https://www.youtube-nocookie.com/embed/{youtube_id}
            $table->string('youtube_id', 11)->index();
            $table->string('status', 20)->default('draft'); // draft | published
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Query website: video published (per kategori), terbaru dulu
            $table->index(['status', 'created_at']);
            $table->index(['guide_category_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guide_videos');
    }
};
