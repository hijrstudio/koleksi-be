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
        // Periode kepengurusan, mis. 2024–2029
        Schema::create('management_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('start_year');
            $table->unsignedSmallInteger('end_year');
            $table->string('status', 20)->default('draft')->index(); // draft | published
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['start_year', 'end_year']);
        });

        // Pengurus per periode: penasihat (ketua, wakil, sekretaris, bendahara, ...) dan bidang
        Schema::create('management_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('management_period_id')->constrained()->cascadeOnDelete();
            $table->string('group', 20); // penasihat | bidang
            $table->string('name', 150);
            $table->json('position'); // {"id": "Ketua Umum", "en": "General Chairman"}
            $table->string('photo')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['management_period_id', 'group', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('management_members');
        Schema::dropIfExists('management_periods');
    }
};
