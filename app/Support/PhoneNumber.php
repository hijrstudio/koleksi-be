<?php

namespace App\Support;

/**
 * Normalisasi nomor HP Indonesia ke format yang disimpan di members.no_hp ("08xxxxxxxxx").
 */
final class PhoneNumber
{
    /**
     * "0811 9331 011 " / "0812-9045-9246" / 81321752222 / "+62812..." → "08xxxxxxxxx"
     *
     * @return string|null|false null = kosong, false = tidak valid
     */
    public static function normalize(mixed $value): string|null|false
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '62')) {
            $digits = '0'.substr($digits, 2);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '0'.$digits;
        }

        return preg_match('/^08\d{7,12}$/', $digits) ? $digits : false;
    }
}
