<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class GalleryPhoto extends Model
{
    use HasFactory;

    public const DISK = 'public';

    /** Foto album diupload satu per satu (AJAX) ke sini sebelum form album disimpan */
    public const DIRECTORY = 'gallery/photos';

    /**
     * Path hasil upload: gallery/photos/{tahun}/{bulan}/{nama acak 40 karakter}.{ext}
     * Dipakai untuk memastikan path yang dikirim form benar-benar hasil upload aplikasi.
     */
    public const PATH_PATTERN = '#^gallery/photos/\d{4}/\d{2}/[A-Za-z0-9]{40}\.(jpe?g|png|webp)$#';

    /**
     * gallery_album_id sengaja tidak fillable: selalu diisi lewat relasi album.
     *
     * @var list<string>
     */
    protected $fillable = [
        'image',
        'sort_order',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(GalleryAlbum::class, 'gallery_album_id');
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image
            ? Storage::disk(self::DISK)->url($this->image)
            : null);
    }

    public static function isUploadPath(mixed $path): bool
    {
        return is_string($path) && preg_match(self::PATH_PATTERN, $path) === 1;
    }
}
