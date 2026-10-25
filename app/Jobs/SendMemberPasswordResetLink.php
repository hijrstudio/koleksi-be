<?php

namespace App\Jobs;

use App\Models\Member;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Password;

/**
 * Kirim link "lupa kata sandi" di queue, bukan di request. Membuat token (bcrypt) + menyimpannya jauh lebih
 * lama untuk e-mail terdaftar daripada tidak terdaftar — kalau dikerjakan di request, selisih waktu respons
 * membocorkan e-mail mana yang terdaftar. Endpoint cukup memasukkan job ini (waktu respons selalu sama).
 */
class SendMemberPasswordResetLink implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $email) {}

    /**
     * Status broker (INVALID_USER / RESET_THROTTLED / RESET_LINK_SENT) sengaja tidak dilaporkan ke mana pun.
     */
    public function handle(): void
    {
        Password::broker('members')->sendResetLink(self::credentials($this->email));
    }

    /**
     * Kriteria pencarian member oleh broker `members`: hanya pendaftaran yang sudah disetujui
     * (yang memang bisa login). Dipakai juga oleh Api\Member\PasswordResetController.
     *
     * @return array<string, string>
     */
    public static function credentials(string $email): array
    {
        return ['email' => $email, 'registration_status' => Member::REG_APPROVED];
    }
}
