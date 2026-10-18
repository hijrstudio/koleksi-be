<?php

namespace App\Notifications;

use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Pendaftaran disetujui pengurus → anggota diminta segera aktivasi dengan membayar iuran pertama
 * lewat dasbor (QRIS, alur yang sama dengan daftar ulang).
 */
class MemberRegistrationApproved extends Notification implements ShouldQueue
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
            ->subject('Pendaftaran KOLEKSI Anda disetujui')
            ->greeting('Halo '.($notifiable->nama_panggilan ?: $notifiable->nama_lengkap).',')
            ->line('Terima kasih telah mendaftar. Pendaftaran Anda telah disetujui pengurus KOLEKSI dengan No. anggota '.$notifiable->noLambungLabel().'.')
            ->line($this->message())
            ->action('Masuk & Bayar Iuran', config('membership.registration.login_url'))
            ->line('Masuk dengan e-mail dan kata sandi yang Anda buat saat mendaftar. Pembayaran menggunakan QRIS (semua e-wallet & mobile banking).')
            ->line('Sampai jumpa di kegiatan KOLEKSI!');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Member $notifiable): array
    {
        return [
            'type' => 'membership.registration_approved',
            'title' => 'Pendaftaran disetujui',
            'message' => $this->message(),
            'amount' => (int) config('membership.annual_fee'),
            'action_url' => config('membership.registration.login_url'),
        ];
    }

    private function message(): string
    {
        $fee = 'Rp'.number_format((int) config('membership.annual_fee'), 0, ',', '.');

        return "Segera aktifkan keanggotaan Anda dengan membayar iuran tahunan sebesar {$fee} melalui dasbor anggota.";
    }
}
