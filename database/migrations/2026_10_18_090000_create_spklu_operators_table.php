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
        Schema::create('spklu_operators', function (Blueprint $table) {
            $table->id();
            $table->string('nama_operator', 150); // badan usaha, mis. "PT PLN (Persero)"
            $table->string('brand_spklu', 100)->unique(); // nama brand tampil di website, mis. "PLN"
            $table->string('logo')->nullable(); // path logo di disk public; kosong = website tampilkan nama brand
            $table->string('aplikasi', 100); // nama aplikasi charging, mis. "PLN Mobile"
            $table->string('link_playstore', 500)->nullable(); // https://play.google.com/...
            $table->string('link_appstore', 500)->nullable(); // https://apps.apple.com/...
            $table->string('status', 20)->default('draft'); // draft | published
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Query website: where status = published order by brand_spklu (paginasi & slider logo)
            $table->index(['status', 'brand_spklu']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spklu_operators');
    }
};
