<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Pengaturan key-value yang diubah pengurus di CMS (bukan rahasia; rahasia tetap di .env).
 * Dibaca lewat cache; cache dibuang saat nilai diubah.
 */
class Setting extends Model
{
    /** Nomor awal pencarian No. lambung anggota baru (lihat NoLambungAllocator). */
    public const NO_LAMBUNG_START = 'members.no_lambung_start';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return Cache::rememberForever(self::cacheKey($key), fn () => static::query()->find($key)?->value) ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::cacheKey($key));
    }

    private static function cacheKey(string $key): string
    {
        return 'settings:'.$key;
    }
}
