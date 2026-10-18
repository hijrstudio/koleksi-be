<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master wilayah Kemendagri (provinsi → kab/kota → kecamatan → desa/kelurahan), diisi RegionSeeder.
 * Kode memakai format resmi bertitik: 31 / 31.74 / 31.74.01 / 31.74.01.1001.
 *
 * Kolom wilayah di `members` sebelumnya disiapkan untuk kode BPS lama tanpa titik (4/7/10 karakter),
 * diubah ke panjang kode Kemendagri (5/8/13) + foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provinces', function (Blueprint $t) {
            $t->char('code', 2)->primary();
            $t->string('name', 60);
        });

        Schema::create('regencies', function (Blueprint $t) {
            $t->char('code', 5)->primary();
            $t->char('province_code', 2)->index();
            $t->string('name', 80)->index();

            $t->foreign('province_code')->references('code')->on('provinces')->restrictOnDelete();
        });

        Schema::create('districts', function (Blueprint $t) {
            $t->char('code', 8)->primary();
            $t->char('regency_code', 5)->index();
            $t->string('name', 80);

            $t->foreign('regency_code')->references('code')->on('regencies')->restrictOnDelete();
        });

        Schema::create('villages', function (Blueprint $t) {
            $t->char('code', 13)->primary();
            $t->char('district_code', 8)->index();
            $t->string('name', 80);

            $t->foreign('district_code')->references('code')->on('districts')->restrictOnDelete();
        });

        // Belum ada member yang mengisi wilayah (format lama tidak punya master) → aman dikosongkan.
        DB::table('members')->update(['city_code' => null, 'district_code' => null, 'village_code' => null]);

        Schema::table('members', function (Blueprint $t) {
            $t->char('city_code', 5)->nullable()->change();
            $t->char('district_code', 8)->nullable()->change();
            $t->char('village_code', 13)->nullable()->change();

            $t->foreign('city_code')->references('code')->on('regencies')->nullOnDelete();
            $t->foreign('district_code')->references('code')->on('districts')->nullOnDelete();
            $t->foreign('village_code')->references('code')->on('villages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $t) {
            $t->dropForeign(['city_code']);
            $t->dropForeign(['district_code']);
            $t->dropForeign(['village_code']);
        });

        DB::table('members')->update(['city_code' => null, 'district_code' => null, 'village_code' => null]);

        Schema::table('members', function (Blueprint $t) {
            $t->char('city_code', 4)->nullable()->change();
            $t->char('district_code', 7)->nullable()->change();
            $t->char('village_code', 10)->nullable()->change();
        });

        Schema::dropIfExists('villages');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('regencies');
        Schema::dropIfExists('provinces');
    }
};
