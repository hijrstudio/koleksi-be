<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jejak audit perubahan status keanggotaan (manual oleh admin maupun otomatis dari iuran)
        Schema::create('member_status_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->enum('from_status', ['active', 'inactive'])->nullable();
            $t->enum('to_status', ['active', 'inactive']);
            $t->enum('mode', ['auto', 'manual']);
            $t->string('reason', 500)->nullable();
            $t->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete(); // null = sistem
            $t->timestamp('created_at')->useCurrent();

            $t->index(['member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_status_logs');
    }
};
