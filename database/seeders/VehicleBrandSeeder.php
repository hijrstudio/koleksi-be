<?php

namespace Database\Seeders;

use App\Models\VehicleBrand;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Daftar awal merek & tipe mobil listrik yang umum di Indonesia.
 * Ini hanya titik awal — lengkapi / koreksi dari CMS (master Merek & Tipe).
 * Aman dijalankan ulang (updateOrCreate / firstOrCreate).
 */
class VehicleBrandSeeder extends Seeder
{
    private const DATA = [
        'Hyundai' => ['Ioniq 5', 'Ioniq 6', 'Kona Electric', 'Ioniq 5 N'],
        'Wuling' => ['Air ev', 'BinguoEV', 'Cloud EV'],
        'BYD' => ['Dolphin', 'Atto 3', 'Seal', 'M6', 'Sealion 7'],
        'Chery' => ['Omoda E5', 'J6'],
        'Tesla' => ['Model 3', 'Model Y', 'Model S', 'Model X'],
        'Toyota' => ['bZ4X'],
        'Lexus' => ['UX 300e', 'RZ 450e'],
        'Kia' => ['EV6', 'EV9'],
        'MG' => ['ZS EV', 'MG4 EV'],
        'Nissan' => ['Leaf'],
        'BMW' => ['i4', 'i7', 'iX', 'iX1'],
        'Mercedes-Benz' => ['EQA', 'EQB', 'EQE', 'EQS'],
        'Neta' => ['V', 'X'],
        'VinFast' => ['VF 3', 'VF 5', 'VF e34', 'VF 6', 'VF 7'],
        'Citroen' => ['e-C3'],
        'Volvo' => ['XC40 Recharge', 'C40 Recharge', 'EX30'],
        'MINI' => ['Cooper SE'],
        'Porsche' => ['Taycan'],
        'Polytron' => ['G3'],
        'Seres' => ['E1'],
        'Aion' => ['Y Plus', 'V'],
        'Denza' => ['D9'],
        'Geely' => ['EX5'],
        'Lainnya' => [],
    ];

    public function run(): void
    {
        $order = 0;

        foreach (self::DATA as $brandName => $models) {
            $brand = VehicleBrand::updateOrCreate(
                ['slug' => Str::slug($brandName)],
                ['name' => $brandName, 'sort_order' => $brandName === 'Lainnya' ? 999 : $order++],
            );

            foreach ($models as $model) {
                $brand->models()->firstOrCreate(['name' => $model]);
            }
        }
    }
}
