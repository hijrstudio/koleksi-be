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
        // Panduan Praktis: infografis (kategori memakai guide_categories)
        Schema::create('guide_infographics', function (Blueprint $table) {
            $table->id();
            // restrict: kategori yang masih punya infografis tidak bisa dihapus
            $table->foreignId('guide_category_id')->constrained()->restrictOnDelete();
            $table->json('title'); // {"id": "...", "en": "..."}
            $table->string('image'); // path gambar infografis di disk public
            $table->string('status', 20)->default('draft'); // draft | published
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Query website: infografis published (per kategori), terbaru dulu
            $table->index(['status', 'created_at']);
            $table->index(['guide_category_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guide_infographics');
    }
};
