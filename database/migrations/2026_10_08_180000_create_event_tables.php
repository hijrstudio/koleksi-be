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
        // Kegiatan: kategori
        Schema::create('event_categories', function (Blueprint $table) {
            $table->id();
            $table->json('name'); // {"id": "...", "en": "..."}
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Kegiatan: event
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            // restrict: kategori yang masih punya event tidak bisa dihapus
            $table->foreignId('event_category_id')->constrained()->restrictOnDelete();
            $table->json('title'); // {"id": "...", "en": "..."}
            $table->string('slug')->unique(); // untuk URL website, otomatis dari title ID
            // Fase (akan datang / berlangsung / selesai) dihitung dari dua tanggal ini, tidak disimpan
            $table->date('start_date');
            $table->date('end_date'); // = start_date untuk event satu hari
            $table->json('description'); // HTML tersanitasi {"id","en"}
            $table->string('location'); // nama tempat / alamat
            // Titik Google Maps (opsional). FE: https://www.google.com/maps?q={latitude},{longitude}
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('registration_info')->nullable(); // HTML tersanitasi {"id","en"}
            $table->string('status', 20)->default('draft'); // draft | published
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Query website per tab: akan datang (start_date), berlangsung & selesai (end_date)
            $table->index(['status', 'start_date']);
            $table->index(['status', 'end_date']);
            $table->index(['event_category_id', 'status', 'start_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
        Schema::dropIfExists('event_categories');
    }
};
