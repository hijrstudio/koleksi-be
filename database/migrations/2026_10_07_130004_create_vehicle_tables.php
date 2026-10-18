<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Master merek — dropdown "Merek"
        Schema::create('vehicle_brands', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60)->unique();
            $t->string('slug', 60)->unique();
            $t->string('logo_path')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        // Master tipe — dropdown "Tipe" (tergantung merek)
        Schema::create('vehicle_models', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_brand_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['vehicle_brand_id', 'name']);
        });

        // Kendaraan milik member (1 member bisa punya >1 mobil)
        Schema::create('member_vehicles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();

            $t->string('vin', 17)->unique();                       // No. rangka (VIN), 17 karakter

            // No. plat (Nopol): [B] [1234] [XYZ]
            $t->string('plat_kode', 2);                             // B, AB, DK ...
            $t->string('plat_nomor', 4);                            // 1–4 digit
            $t->string('plat_seri', 3)->nullable();                 // 0–3 huruf
            $t->string('plat_full', 12)->unique();                  // dinormalisasi: "B1234XYZ"
            $t->enum('plat_status', ['permanen', 'sementara'])->default('permanen');

            $t->foreignId('vehicle_brand_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('vehicle_model_id')->nullable()->constrained()->nullOnDelete();
            $t->string('brand_other', 60)->nullable();              // kalau merek/tipe belum ada di master
            $t->string('model_other', 80)->nullable();
            $t->year('tahun')->nullable();
            $t->string('warna', 30)->nullable();

            // Verifikasi pengurus atas bukti dokumen
            $t->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending')->index();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->text('rejection_reason')->nullable();

            $t->boolean('is_primary')->default(false);              // mobil utama (mis. untuk kartu anggota)
            $t->timestamps();

            $t->index(['member_id', 'is_primary']);
        });

        // Bukti dokumen (STNK, SPK, Invoice) — bisa lebih dari satu file per kendaraan
        Schema::create('member_vehicle_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_vehicle_id')->constrained()->cascadeOnDelete();
            $t->enum('type', ['stnk', 'spk', 'invoice', 'lainnya'])->default('stnk');
            $t->string('disk', 20)->default('local');               // disk privat, bukan public
            $t->string('path');
            $t->string('original_name');
            $t->string('mime_type', 100);
            $t->unsignedInteger('size');                            // byte, maks 10 MB
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_vehicle_documents');
        Schema::dropIfExists('member_vehicles');
        Schema::dropIfExists('vehicle_models');
        Schema::dropIfExists('vehicle_brands');
    }
};
