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
        // Panduan Praktis: kategori
        Schema::create('guide_categories', function (Blueprint $table) {
            $table->id();
            $table->json('name'); // {"id": "...", "en": "..."}
            $table->string('status', 20)->default('draft')->index(); // draft | published
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Panduan Praktis: artikel
        Schema::create('guide_articles', function (Blueprint $table) {
            $table->id();
            // restrict: kategori yang masih punya artikel tidak bisa dihapus
            $table->foreignId('guide_category_id')->constrained()->restrictOnDelete();
            $table->json('title'); // {"id": "...", "en": "..."}
            $table->string('slug')->unique(); // untuk URL website, dibuat dari title ID
            $table->json('content'); // {"id": "<p>...</p>", "en": "..."} HTML tersanitasi
            $table->string('image')->nullable();

            // Penulis: tepat satu yang terisi (dijaga di model). Dipisah agar keduanya punya foreign key asli.
            $table->foreignId('author_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 20)->default('draft'); // draft | published
            $table->timestamp('published_at')->nullable(); // diisi saat pertama kali dipublish
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Query website: artikel published terbaru (per kategori)
            $table->index(['status', 'published_at']);
            $table->index(['guide_category_id', 'status', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guide_articles');
        Schema::dropIfExists('guide_categories');
    }
};
