<?php

namespace Database\Seeders;

use App\Models\Member;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Upload file bawaan aplikasi (mis. foto default member) ke disk storage-nya.
 * storage/app/public ter-gitignore & disk di Laravel Cloud = bucket, jadi file ini disimpan di database/data
 * dan disalin dari sini. Aman dijalankan ulang: file yang sudah ada tidak ditimpa.
 */
class DefaultAssetSeeder extends Seeder
{
    /** @var list<array{disk: string, path: string, source: string}> */
    private const ASSETS = [
        ['disk' => Member::PHOTO_DISK, 'path' => Member::DEFAULT_PHOTO, 'source' => 'database/data/members/user.png'],
    ];

    public function run(): void
    {
        foreach (self::ASSETS as $asset) {
            $disk = Storage::disk($asset['disk']);

            if (! $disk->exists($asset['path'])) {
                $disk->put($asset['path'], file_get_contents(base_path($asset['source'])), 'public');
            }
        }
    }
}
