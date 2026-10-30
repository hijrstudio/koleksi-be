<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Support\Carbon;

/**
 * Token link "bayar iuran pendaftaran" (di e-mail & setelah klik verifikasi). Bukan token login: hanya membuka
 * halaman bayar iuran pertama milik pendaftar itu (lihat Api\RegistrationPaymentController).
 *
 * Format: {member_id}.{expires_unix}.{hmac}. HMAC-SHA256 dengan APP_KEY atas id, kedaluwarsa, dan e-mail —
 * token tidak bisa dipalsukan / diperpanjang, dan tidak berlaku lagi kalau e-mail pendaftar berubah.
 */
class RegistrationPaymentToken
{
    public static function make(Member $member, Carbon $expiresAt): string
    {
        $expires = $expiresAt->getTimestamp();

        return $member->getKey().'.'.$expires.'.'.self::signature((int) $member->getKey(), $expires, (string) $member->email);
    }

    /** Member pemilik token, atau null kalau token rusak / palsu / kedaluwarsa. */
    public static function member(?string $token): ?Member
    {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 3 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }

        [$id, $expires, $signature] = [(int) $parts[0], (int) $parts[1], $parts[2]];
        if ($expires < now()->getTimestamp()) {
            return null;
        }

        $member = Member::query()->find($id);
        if (! $member || ! hash_equals(self::signature($id, $expires, (string) $member->email), $signature)) {
            return null;
        }

        return $member;
    }

    private static function signature(int $id, int $expires, string $email): string
    {
        $key = (string) config('app.key');

        return hash_hmac('sha256', "registration-payment|{$id}|{$expires}|".sha1($email), $key);
    }
}
