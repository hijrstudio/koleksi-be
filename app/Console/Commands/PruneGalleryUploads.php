<?php

namespace App\Console\Commands;

use App\Models\GalleryPhoto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Hapus foto galeri yang sudah diupload (AJAX) tetapi tidak pernah disimpan ke album,
 * mis. form album ditutup sebelum disimpan atau foto dihapus dari repeater sebelum simpan.
 *
 *   php artisan gallery:prune-uploads --dry-run
 *   php artisan gallery:prune-uploads --hours=24
 *
 * Hanya file yang lebih tua dari --hours yang dihapus, supaya foto di form yang sedang
 * dikerjakan (belum disimpan) tidak ikut terhapus.
 */
class PruneGalleryUploads extends Command
{
    protected $signature = 'gallery:prune-uploads
        {--hours=24 : Umur minimal file (jam) sebelum boleh dihapus}
        {--dry-run : Hanya tampilkan file yang akan dihapus}';

    protected $description = 'Hapus foto galeri hasil upload yang tidak dipakai album mana pun';

    private const CHUNK = 500;

    public function handle(): int
    {
        $disk = Storage::disk(GalleryPhoto::DISK);
        $cutoff = now()->subHours(max(1, (int) $this->option('hours')))->getTimestamp();

        $candidates = collect($disk->allFiles(GalleryPhoto::DIRECTORY))
            ->filter(fn (string $path) => $disk->lastModified($path) < $cutoff);

        // Cek pemakaian per chunk lewat unique index gallery_photos.image (hemat memori & cepat)
        $orphans = $candidates->chunk(self::CHUNK)->flatMap(function ($chunk) {
            $used = GalleryPhoto::query()->whereIn('image', $chunk->all())->pluck('image')->flip();

            return $chunk->reject(fn (string $path) => $used->has($path));
        })->values();

        if ($orphans->isEmpty()) {
            $this->info('Tidak ada foto galeri yang perlu dihapus.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $orphans->each(fn (string $path) => $this->line($path));
            $this->info("{$orphans->count()} foto akan dihapus (dry run).");

            return self::SUCCESS;
        }

        $orphans->chunk(self::CHUNK)->each(fn ($chunk) => $disk->delete($chunk->all()));
        $this->info("{$orphans->count()} foto galeri yang tidak dipakai telah dihapus.");

        return self::SUCCESS;
    }
}
