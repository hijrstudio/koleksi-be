<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Import master wilayah dari database/data/wilayah/*.csv (lihat README di folder itu).
 * Upsert per kode → aman dijalankan ulang untuk update nama/penambahan wilayah.
 */
class RegionSeeder extends Seeder
{
    private const CHUNK = 2000;

    /** tabel => [file csv, kolom induk, panjang prefix kode induk] */
    private const LEVELS = [
        'provinces' => ['provinces.csv', null, 0],
        'regencies' => ['regencies.csv', 'province_code', 2],
        'districts' => ['districts.csv', 'regency_code', 5],
        'villages' => ['villages.csv', 'district_code', 8],
    ];

    public function run(): void
    {
        foreach (self::LEVELS as $table => [$file, $parentColumn, $parentLength]) {
            $count = 0;

            foreach ($this->chunks(database_path("data/wilayah/{$file}")) as $rows) {
                $records = array_map(fn (array $r) => array_filter([
                    'code' => $r[0],
                    'name' => $r[1],
                    $parentColumn => $parentColumn ? substr($r[0], 0, $parentLength) : null,
                ], fn ($v) => $v !== null), $rows);

                DB::table($table)->upsert($records, ['code'], ['name']);
                $count += count($records);
            }

            $this->command?->info("{$table}: {$count}");
        }
    }

    /**
     * @return \Generator<int, array<int, array{0: string, 1: string}>>
     */
    private function chunks(string $path): \Generator
    {
        $handle = fopen($path, 'r') ?: throw new RuntimeException("File tidak ditemukan: {$path}");
        fgetcsv($handle, escape: ''); // header

        $chunk = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            if (count($row) < 2 || $row[0] === '') {
                continue;
            }

            $chunk[] = [trim($row[0]), trim($row[1])];
            if (count($chunk) === self::CHUNK) {
                yield $chunk;
                $chunk = [];
            }
        }

        if ($chunk) {
            yield $chunk;
        }

        fclose($handle);
    }
}
