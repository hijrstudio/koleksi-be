<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guide_playbooks', function (Blueprint $table) {
            $table->id();
            $table->json('title'); // {"id": "...", "en": "..."}
            $table->string('cover_image'); // path cover di disk public
            $table->string('file_pdf'); // path PDF di disk private (local): tidak bisa diakses langsung lewat URL
            $table->string('status', 20)->default('draft'); // draft | published
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Query website: playbook published, terbaru dulu
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_playbooks');
    }
};
