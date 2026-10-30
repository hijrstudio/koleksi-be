<?php

namespace App\Notifications;

use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Iuran pendaftaran lunas → akun aktif, dapat No. anggota (lambung), bisa login.
 */
class MemberRegistrationActivated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(Member $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Selamat bergabung di KOLEKSI, No. anggota '.$notifiable->noLambungLabel())
            ->greeting('Halo '.($notifiable->nama_panggilan ?: $notifiable->nama_lengkap).',')
            ->line('Pembayaran iuran Anda sudah kami terima. Keanggotaan KOLEKSI Anda kini aktif.')
            ->line('No. anggota (lambung) Anda: '.$notifiable->noLambungLabel())
            ->action('Masuk ke Dasbor Anggota', config('membership.registration.login_url'))
            ->line('Masuk dengan e-mail dan kata sandi yang Anda buat saat mendaftar.')
            ->line('Sampai jumpa di kegiatan KOLEKSI!');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Member $notifiable): array
    {
        return [
            'type' => 'membership.registration_activated',
            'title' => 'Selamat bergabung di KOLEKSI',
            'message' => 'Keanggotaan Anda aktif dengan No. anggota '.$notifiable->noLambungLabel().'.',
            'no_lambung' => $notifiable->no_lambung,
        ];
    }
}
