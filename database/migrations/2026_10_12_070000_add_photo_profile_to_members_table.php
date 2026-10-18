<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $t) {
            // Path relatif di disk "public" (mis. members/photos/abc.jpg). Kosong → avatar default.
            $t->string('photo_profile')->nullable()->after('nama_panggilan');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $t) {
            $t->dropColumn('photo_profile');
        });
    }
};
