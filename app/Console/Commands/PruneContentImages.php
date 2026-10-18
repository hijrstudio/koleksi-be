<?php

namespace App\Console\Commands;

use App\Support\ContentImages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Hapus gambar konten rich editor (artikel panduan, berita, ...) yang tidak dipakai konten mana pun, mis.:
 * diupload lalu formnya batal disimpan, dihapus dari konten, atau datanya dihapus.
 *
 *   php artisan content:prune-images --dry-run
 *   php artisan content:prune-images --hours=24
 *
 * Pemakaian dicek lintas semua modul di ContentImages::SOURCES, karena gambar bisa ikut tersalin
 * dari satu modul ke modul lain. Hanya file yang lebih tua dari --hours yang dihapus, supaya gambar
 * di form yang sedang dikerjakan (belum disimpan) tidak ikut terhapus.
 */
class PruneContentImages extends Command
{
    protected $signature = 'content:prune-images
        {--hours=24 : Umur minimal file (jam) sebelum boleh dihapus}
        {--dry-run : Hanya tampilkan file yang akan dihapus}';

    protected $description = 'Hapus gambar konten rich editor yang tidak lagi dipakai';

    private const CHUNK = 200;

    public function handle(): int
    {
        $disk = Storage::disk(ContentImages::DISK);
        $cutoff = now()->subHours(max(1, (int) $this->option('hours')))->getTimestamp();

        // Kumpulkan semua gambar yang dipakai (per chunk agar hemat memori walau datanya ribuan)
        $used = [];
        foreach (array_keys(ContentImages::SOURCES) as $model) {
            $model::query()->select(['id', 'content'])->chunkById(self::CHUNK, function ($rows) use (&$used) {
                foreach ($rows as $row) {
                    foreach (ContentImages::pathsIn($row->content) as $path) {
                        $used[$path] = true;
                    }
                }
            });
        }

        $orphans = collect(ContentImages::SOURCES)
            ->flatMap(fn (string $directory) => $disk->allFiles($directory))
            ->reject(fn (string $path) => isset($used[$path]))
            ->filter(fn (string $path) => $disk->lastModified($path) < $cutoff)
            ->values();

        if ($orphans->isEmpty()) {
            $this->info('Tidak ada gambar konten yang perlu dihapus.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $orphans->each(fn (string $path) => $this->line($path));
            $this->info("{$orphans->count()} gambar akan dihapus (dry run).");

            return self::SUCCESS;
        }

        $orphans->chunk(500)->each(fn ($chunk) => $disk->delete($chunk->all()));
        $this->info("{$orphans->count()} gambar konten yang tidak dipakai telah dihapus.");

        return self::SUCCESS;
    }
}
