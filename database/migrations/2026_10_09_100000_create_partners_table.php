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
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150); // nama partner (nama diri, tidak diterjemahkan)
            $table->string('logo'); // path logo di disk public
            $table->string('url', 500)->nullable(); // website partner (http/https)
            $table->unsignedInteger('sort')->default(0); // urutan tampil, kecil = lebih dulu
            $table->string('status', 20)->default('draft'); // draft | published
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Query website: where status = published order by sort
            $table->index(['status', 'sort']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
