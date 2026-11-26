<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Gambar sampul diskusi forum: di-decode ulang (EXIF & konten sisipan terbuang, lihat ProfilePhoto::decode),
 * diperkecil ke lebar maks MAX_WIDTH tanpa crop, lalu disimpan WebP.
 */
final class CoverImage
{
    public const MAX_WIDTH = 1200;

    public const QUALITY = 80;

    /** @return string biner WebP */
    public static function process(UploadedFile $file): string
    {
        $source = ProfilePhoto::decode($file);
        $width = imagesx($source);
        $height = imagesy($source);
        $targetWidth = min(self::MAX_WIDTH, $width);
        $targetHeight = max(1, (int) round($height * $targetWidth / $width));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagewebp($canvas, null, self::QUALITY);
        $binary = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        if ($binary === '') {
            throw new RuntimeException('Gagal memproses gambar.');
        }

        return $binary;
    }
}
