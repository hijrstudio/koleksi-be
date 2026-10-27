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
        // Panduan Praktis: dokumen regulasi (PDF). Judul tidak bilingual: nama resmi dokumen dari pemerintah.
        Schema::create('guide_regulations', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('file_pdf'); // path di disk private (tanpa URL publik), dikirim lewat API
            $table->string('status', 20)->default('draft'); // draft | published
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Query website: dokumen published, terbaru dulu
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guide_regulations');
    }
};
