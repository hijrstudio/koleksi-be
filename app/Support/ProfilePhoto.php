<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Normalisasi foto profil: koreksi orientasi EXIF, crop persegi di tengah, resize ke SIZE px, simpan WebP.
 *
 * Gambar di-encode ulang (bukan disimpan apa adanya), jadi metadata EXIF — termasuk lokasi GPS dari kamera HP —
 * ikut terbuang, dan konten non-gambar yang disisipkan di file tidak ikut tersimpan.
 */
final class ProfilePhoto
{
    public const SIZE = 512;

    public const QUALITY = 82;

    /** Batas piksel sebelum di-decode (anti decompression bomb), selaras dengan rule `dimensions` di request. */
    public const MAX_PIXELS = 6000 * 6000;

    /** @return string biner WebP */
    public static function process(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $info = $path ? @getimagesize($path) : false;

        if (! $info || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new RuntimeException('Foto tidak valid atau terlalu besar.');
        }

        $source = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };

        if (! $source instanceof GdImage) {
            throw new RuntimeException('Format foto tidak didukung.');
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $source = self::applyExifOrientation($source, $path);
        }

        // Crop persegi di tengah, lalu resize
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $target = min(self::SIZE, $side);

        $canvas = imagecreatetruecolor($target, $target);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled(
            $canvas, $source,
            0, 0, (int) (($width - $side) / 2), (int) (($height - $side) / 2),
            $target, $target, $side, $side,
        );

        ob_start();
        imagewebp($canvas, null, self::QUALITY);
        $binary = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        if ($binary === '') {
            throw new RuntimeException('Gagal memproses foto.');
        }

        return $binary;
    }

    /** Foto dari kamera HP sering tersimpan miring + tag Orientation; putar supaya tegak. */
    private static function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        $orientation = function_exists('exif_read_data') ? (@exif_read_data($path)['Orientation'] ?? 1) : 1;

        $rotated = match ((int) $orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if ($rotated instanceof GdImage && $rotated !== $image) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }
}
