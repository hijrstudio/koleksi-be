<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Pengaturan key-value yang diubah pengurus di CMS (bukan rahasia; rahasia tetap di .env).
 * Dibaca lewat cache; cache dibuang saat nilai diubah.
 */
class Setting extends Model
{
    /** Nomor awal pencarian No. lambung anggota baru (lihat NoLambungAllocator). */
    public const NO_LAMBUNG_START = 'members.no_lambung_start';

    /** Biaya pendaftaran anggota baru (Rupiah), lihat App\Services\MembershipFees. */
    public const REGISTRATION_FEE = 'membership.registration_fee';

    /** Iuran tahunan / perpanjangan (Rupiah), lihat App\Services\MembershipFees. */
    public const ANNUAL_FEE = 'membership.annual_fee';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value', 'updated_by'];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return Cache::rememberForever(self::cacheKey($key), fn () => static::query()->find($key)?->value) ?? $default;
    }

    /** $by = admin CMS yang mengubah (dicatat sebagai jejak). */
    public static function put(string $key, ?string $value, ?User $by = null): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by?->id]);
        Cache::forget(self::cacheKey($key));
    }

    private static function cacheKey(string $key): string
    {
        return 'settings:'.$key;
    }
}
