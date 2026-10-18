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
        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->string('category', 30); // App\Enums\NewsCategory: news_release | ev_news | coverage
            $table->json('title'); // {"id": "...", "en": "..."}
            $table->string('slug')->unique(); // untuk URL website, otomatis dari title ID
            $table->string('image')->nullable(); // path gambar utama
            $table->json('content'); // {"id": "<p>...</p>", "en": "..."} HTML tersanitasi

            // Penulis: tepat satu yang terisi (dijaga di model). Dipisah agar keduanya punya foreign key asli.
            $table->foreignId('author_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 20)->default('draft'); // draft | published
            $table->timestamp('published_at')->nullable(); // diisi saat pertama kali dipublish
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Query website: berita published terbaru (per kategori)
            $table->index(['status', 'published_at']);
            $table->index(['category', 'status', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news');
    }
};
