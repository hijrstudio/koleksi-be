<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spesifikasi pengisian daya untuk halaman EV Garage + jejak penulis CMS.
     * Semua kolom nullable: data lama (dropdown form member) tetap valid tanpa spesifikasi,
     * dan tipe tanpa spesifikasi tidak ditampilkan di EV Garage.
     */
    public function up(): void
    {
        Schema::table('vehicle_brands', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('sort_order')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });

        Schema::table('vehicle_models', function (Blueprint $table) {
            $table->decimal('battery_kwh', 5, 1)->nullable()->after('name'); // kapasitas baterai (kWh)
            $table->decimal('ac_kw', 5, 1)->nullable()->after('battery_kwh'); // daya AC maks. (kW)
            $table->decimal('dc_kw', 5, 1)->nullable()->after('ac_kw'); // daya DC maks. (kW); null = tidak mendukung DC
            $table->unsignedSmallInteger('range_km')->nullable()->after('dc_kw'); // jangkauan (km)
            $table->foreignId('created_by')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_models', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['battery_kwh', 'ac_kw', 'dc_kw', 'range_km']);
        });

        Schema::table('vehicle_brands', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
        });
    }
};
