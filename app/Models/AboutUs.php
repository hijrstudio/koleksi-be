<?php

namespace App\Models;

use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Konten halaman "Tentang Kami". Tabel singleton: selalu 1 row dengan id = SINGLETON_ID,
 * sehingga primary key menjamin tidak akan pernah ada row kedua.
 */
class AboutUs extends Model
{
    use TracksAuthor;

    public const SINGLETON_ID = 1;

    public const DISK = 'public';

    public const DIRECTORY = 'about-us';

    /** Kolom json {"id","en"} yang diisi lewat rich text editor (HTML tersanitasi) */
    public const RICH_TEXT_FIELDS = ['summary', 'profil_organisasi', 'misi', 'syarat_anggota', 'daftar_ulang_anggota'];

    /** Kolom json {"id","en"} berupa teks biasa */
    public const PLAIN_TEXT_FIELDS = ['visi'];

    /** Kolom yang menyimpan path file di DISK */
    public const FILE_FIELDS = ['image', 'aturan_ad_art', 'sponsor_doc', 'mitra_doc'];

    public const LOCALES = ['id', 'en'];

    protected $table = 'about_us';

    /**
     * id, created_by, updated_by sengaja tidak fillable.
     *
     * @var list<string>
     */
    protected $fillable = [
        ...self::RICH_TEXT_FIELDS,
        ...self::PLAIN_TEXT_FIELDS,
        ...self::FILE_FIELDS,
    ];

    protected function casts(): array
    {
        return array_fill_keys([...self::RICH_TEXT_FIELDS, ...self::PLAIN_TEXT_FIELDS], 'array');
    }

    /**
     * Row "Tentang Kami" (instance baru yang belum tersimpan jika belum pernah diisi).
     */
    public static function current(): self
    {
        return static::query()->find(self::SINGLETON_ID) ?? new static;
    }

    /**
     * Nilai kolom json sesuai locale, fallback ke bahasa Indonesia.
     */
    public function translate(string $field, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $value = $this->{$field};

        return $value[$locale] ?? $value['id'] ?? null;
    }

    public function fileUrl(string $field): ?string
    {
        $path = $this->{$field};

        return $path ? Storage::disk(self::DISK)->url($path) : null;
    }
}
