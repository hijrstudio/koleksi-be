<?php

namespace App\Notifications;

use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Link verifikasi e-mail untuk pendaftar baru. Link = signed URL ke backend (bertanda tangan & kedaluwarsa),
 * berisi sha1(e-mail) supaya link lama tidak berlaku kalau e-mail berubah. Setelah diklik, backend mengarahkan
 * ke halaman hasil verifikasi di FE (lihat Api\MemberRegistrationController::verify).
 */
class VerifyMemberRegistrationEmail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(Member $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(Member $notifiable): MailMessage
    {
        $hours = intdiv(config('membership.registration.verify_ttl_minutes'), 60);

        return (new MailMessage)
            ->subject('Verifikasi e-mail pendaftaran KOLEKSI')
            ->greeting('Halo '.($notifiable->nama_panggilan ?: $notifiable->nama_lengkap).',')
            ->line('Terima kasih sudah mendaftar menjadi anggota KOLEKSI. Klik tombol di bawah untuk memverifikasi alamat e-mail Anda.')
            ->action('Verifikasi E-mail', self::verificationUrl($notifiable))
            ->line("Link ini berlaku {$hours} jam. Setelah e-mail terverifikasi, Anda langsung diarahkan ke halaman pembayaran iuran pertama untuk mengaktifkan keanggotaan.")
            ->line('Kalau Anda tidak merasa mendaftar di KOLEKSI, abaikan e-mail ini.');
    }

    public static function verificationUrl(Member $member): string
    {
        return URL::temporarySignedRoute(
            'api.v1.registrations.verify',
            now()->addMinutes(config('membership.registration.verify_ttl_minutes')),
            ['member' => $member->getKey(), 'hash' => sha1((string) $member->email)],
        );
    }
}
