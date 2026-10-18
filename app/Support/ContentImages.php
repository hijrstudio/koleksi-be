<?php

namespace App\Support;

use App\Models\GuideArticle;
use App\Models\News;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Registry folder gambar yang disisipkan di konten rich editor (artikel, berita, ...).
 *
 * Dipakai oleh:
 *  - RichTextSanitizer: <img src> hanya boleh dari folder-folder ini.
 *  - content:prune-images: file di folder ini yang tidak dipakai konten mana pun dihapus.
 *
 * Tambah modul baru = tambah satu baris di SOURCES (model => folder). Model wajib punya kolom json `content`.
 */
final class ContentImages
{
    public const DISK = 'public';

    /** @var array<class-string<Model>, string> */
    public const SOURCES = [
        GuideArticle::class => GuideArticle::CONTENT_IMAGE_DIRECTORY,
        News::class => News::CONTENT_IMAGE_DIRECTORY,
    ];

    public const MAX_KB = 5 * 1024;

    public const MAX_DIMENSION = 8000;

    public const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * Aturan validasi untuk endpoint upload gambar konten.
     *
     * @return list<string>
     */
    public static function uploadRules(): array
    {
        return [
            'required', 'file',
            'mimetypes:'.implode(',', self::MIMES),
            'extensions:'.implode(',', self::EXTENSIONS),
            'max:'.self::MAX_KB,
            'dimensions:max_width='.self::MAX_DIMENSION.',max_height='.self::MAX_DIMENSION,
        ];
    }

    /**
     * @return array{path: string, url: string}
     */
    public static function store(UploadedFile $file, string $directory): array
    {
        $path = $file->store($directory.'/'.now()->format('Y/m'), self::DISK);

        return ['path' => $path, 'url' => Storage::disk(self::DISK)->url($path)];
    }

    /**
     * URL publik sebuah folder, mis. "https://domain/storage/news/content/".
     */
    public static function baseUrl(string $directory): string
    {
        return rtrim(Storage::disk(self::DISK)->url($directory), '/').'/';
    }

    /**
     * URL publik semua folder terdaftar (prefix yang diizinkan di <img src>).
     *
     * @return list<string>
     */
    public static function baseUrls(): array
    {
        return array_map(self::baseUrl(...), array_values(self::SOURCES));
    }

    /**
     * Path (relatif ke DISK) semua gambar konten terdaftar yang dipakai di HTML, untuk semua bahasa.
     * Mengecek semua folder, karena gambar bisa ikut tersalin dari modul lain (mis. artikel -> berita).
     *
     * @param  array<string, ?string>|null  $content
     * @return list<string>
     */
    public static function pathsIn(?array $content): array
    {
        $prefixes = [];
        foreach (self::SOURCES as $directory) {
            $prefixes[$directory] = self::baseUrl($directory);
        }

        $paths = [];

        foreach ($content ?? [] as $html) {
            preg_match_all('/<img\s[^>]*src="([^"]+)"/i', (string) $html, $matches);

            foreach ($matches[1] as $src) {
                $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5);

                foreach ($prefixes as $directory => $baseUrl) {
                    if (str_starts_with($src, $baseUrl)) {
                        $paths[] = $directory.'/'.substr($src, strlen($baseUrl));
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }
}
