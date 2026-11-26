<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak perubahan pengaturan CMS (mis. biaya pendaftaran & iuran tahunan): admin yang terakhir mengubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $t) {
            $t->foreignId('updated_by')->nullable()->after('value')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $t) {
            $t->dropConstrainedForeignId('updated_by');
        });
    }
};
