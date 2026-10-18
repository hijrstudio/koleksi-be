<?php

namespace App\Support;

/**
 * Ambil titik koordinat (latitude, longitude) dari input admin:
 *  - koordinat polos            "-6.175392, 106.827153"
 *  - link Google Maps titik     https://www.google.com/maps/place/.../@-6.17,106.82,17z/data=!3d-6.175392!4d106.827153
 *  - link Google Maps view      https://www.google.com/maps/@-6.175392,106.827153,15z
 *  - link query                 https://maps.google.com/?q=-6.175392,106.827153
 *
 * Link pendek (maps.app.goo.gl) tidak bisa dibaca tanpa membuka link-nya, jadi ditolak.
 * Logika yang sama ada di resources/js/components/location-field.js untuk preview di form.
 */
final class GeoCoordinates
{
    public const PRECISION = 7;

    private const NUMBER = '(-?\d{1,3}(?:\.\d+)?)';

    /**
     * @return array{lat: float, lng: float}|null
     */
    public static function parse(?string $input): ?array
    {
        $input = trim(rawurldecode((string) $input));

        if ($input === '' || strlen($input) > 2000) {
            return null;
        }

        $n = self::NUMBER;
        $patterns = [
            "/^{$n}\s*,\s*{$n}$/",                       // koordinat polos
            "/!3d{$n}!4d{$n}/",                          // titik pin tempat (paling akurat)
            "/[?&](?:q|query|ll|destination|center)={$n}\s*,\s*{$n}/",
            "/@{$n},{$n}/",                              // posisi tampilan peta
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input, $m)) {
                return self::valid((float) $m[1], (float) $m[2]);
            }
        }

        return null;
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private static function valid(float $lat, float $lng): ?array
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
            return null;
        }

        return ['lat' => round($lat, self::PRECISION), 'lng' => round($lng, self::PRECISION)];
    }
}
