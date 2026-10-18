<?php

namespace Database\Seeders;

use App\Models\VehicleBrand;
use Illuminate\Database\Seeder;

/**
 * Mengisi spesifikasi EV Garage (baterai, AC, DC, jangkauan) untuk tipe yang SUDAH ADA di master.
 * Tidak menambah / menghapus merek & tipe; tipe yang tidak ditemukan dilewati.
 *
 * PERKIRAAN, bukan data resmi: angka diambil dari spesifikasi umum varian populer
 * (jangkauan campuran WLTP / klaim pabrikan). Koreksi lewat CMS EV Garage bila ada data resmi.
 * Aman dijalankan ulang: php artisan db:seed --class=VehicleSpecSeeder
 */
class VehicleSpecSeeder extends Seeder
{
    /**
     * merek => [tipe => [baterai kWh, AC kW, DC kW (null = tidak mendukung DC), jangkauan km]]
     */
    private const SPECS = [
        'Hyundai' => [
            'Ioniq 5' => [72.6, 11, 220, 451],
            'Ioniq 6' => [77.4, 11, 240, 614],
            'Kona Electric' => [64.8, 11, 100, 484],
            'Ioniq 5 N' => [84, 10.9, 238, 448],
        ],
        'Wuling' => [
            'Air ev' => [26.7, 3.3, null, 300],
            'BinguoEV' => [37.9, 6.6, 30, 410],
            'Cloud EV' => [50.6, 6.6, 50, 460],
        ],
        'BYD' => [
            'Dolphin' => [60.5, 7, 88, 490],
            'Atto 3' => [60.5, 7, 88, 480],
            'Seal' => [82.5, 11, 150, 650],
            'M6' => [71.8, 7, 89, 530],
            'Sealion 7' => [82.5, 11, 150, 542],
        ],
        'Chery' => [
            'Omoda E5' => [61, 6.6, 80, 430],
            'J6' => [65.7, 6.6, 60, 426],
        ],
        'Tesla' => [
            'Model 3' => [60, 11, 170, 513],
            'Model Y' => [60, 11, 170, 455],
            'Model S' => [100, 11, 250, 634],
            'Model X' => [100, 11, 250, 576],
        ],
        'Toyota' => [
            'bZ4X' => [71.4, 6.6, 150, 500],
        ],
        'Lexus' => [
            'UX 300e' => [72.8, 6.6, 50, 450],
            'RZ 450e' => [71.4, 6.6, 150, 440],
        ],
        'Kia' => [
            'EV6' => [77.4, 11, 240, 528],
            'EV9' => [99.8, 11, 210, 541],
        ],
        'MG' => [
            'ZS EV' => [51.1, 7, 76, 320],
            'MG4 EV' => [64, 7, 135, 435],
        ],
        'Nissan' => [
            'Leaf' => [40, 6.6, 50, 270],
        ],
        'BMW' => [
            'i4' => [83.9, 11, 205, 590],
            'i7' => [101.7, 11, 195, 625],
            'iX' => [111.5, 11, 195, 620],
            'iX1' => [64.7, 11, 130, 438],
        ],
        'Mercedes-Benz' => [
            'EQA' => [66.5, 11, 100, 426],
            'EQB' => [66.5, 11, 100, 419],
            'EQE' => [90.6, 11, 170, 639],
            'EQS' => [107.8, 11, 200, 770],
        ],
        'Citroen' => [
            'e-C3' => [44, 7.4, 100, 320],
        ],
        'Volvo' => [
            'XC40 Recharge' => [78, 11, 150, 418],
            'C40 Recharge' => [78, 11, 150, 444],
            'EX30' => [64, 11, 153, 476],
        ],
        'MINI' => [
            'Cooper SE' => [54.2, 11, 95, 402],
        ],
        'Porsche' => [
            'Taycan' => [93.4, 11, 270, 484],
        ],
        'Aion' => [
            'Y Plus' => [63.2, 6.6, 80, 430],
            'V' => [72, 11, 150, 550],
        ],
    ];

    public function run(): void
    {
        $updated = 0;
        $missing = [];

        // Cocokkan tanpa membedakan huruf besar/kecil: nama di CMS bisa saja sedikit berbeda kapitalisasinya
        $brands = VehicleBrand::with('models')->get()->keyBy(fn (VehicleBrand $b) => mb_strtolower($b->name));

        foreach (self::SPECS as $brandName => $models) {
            $brand = $brands->get(mb_strtolower($brandName));

            foreach ($models as $modelName => [$battery, $ac, $dc, $range]) {
                $model = $brand?->models->first(fn ($m) => mb_strtolower($m->name) === mb_strtolower($modelName));

                if (! $model) {
                    $missing[] = "{$brandName} {$modelName}";

                    continue;
                }

                $model->update([
                    'battery_kwh' => $battery,
                    'ac_kw' => $ac,
                    'dc_kw' => $dc,
                    'range_km' => $range,
                ]);
                $updated++;
            }
        }

        $this->command?->info("Spesifikasi diperbarui: {$updated} tipe.");

        if ($missing) {
            $this->command?->warn('Dilewati (tidak ada di master): '.implode(', ', $missing));
        }
    }
}
