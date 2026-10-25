<?php

namespace App\Notifications;

use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Link "atur ulang kata sandi" (lupa kata sandi). Tombolnya ke halaman FE, yang meneruskan token + e-mail
 * ke backend (Api\Member\PasswordResetController). Token sekali pakai & kedaluwarsa (passwords.members.expire).
 *
 * Lewat queue supaya waktu respons permintaan link tidak bergantung pada SMTP — token sempat tersimpan di
 * tabel jobs sampai terkirim (detik), dan tetap tidak berlaku setelah masa berlakunya habis.
 */
class ResetMemberPassword extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        #[\SensitiveParameter] public readonly string $token,
    ) {}

    /**
     * @return list<string>
     */
    public function via(Member $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(Member $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.members.expire');

        return (new MailMessage)
            ->subject('Atur ulang kata sandi akun KOLEKSI')
            ->greeting('Halo '.($notifiable->nama_panggilan ?: $notifiable->nama_lengkap).',')
            ->line('Kami menerima permintaan untuk mengatur ulang kata sandi akun KOLEKSI Anda. Klik tombol di bawah untuk membuat kata sandi baru.')
            ->action('Atur Ulang Kata Sandi', self::resetUrl($notifiable, $this->token))
            ->line("Link ini berlaku {$minutes} menit dan hanya bisa dipakai sekali.")
            ->line('Kalau Anda tidak meminta pengaturan ulang kata sandi, abaikan e-mail ini — kata sandi Anda tidak berubah.');
    }

    public static function resetUrl(Member $member, #[\SensitiveParameter] string $token): string
    {
        return config('membership.password_reset_url').'?'.http_build_query([
            'token' => $token,
            'email' => $member->getEmailForPasswordReset(),
        ]);
    }
}
