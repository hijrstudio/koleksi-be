<?php

namespace App\Notifications;

use App\Models\Member;
use App\Services\MemberRegistrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Link halaman bayar iuran pendaftaran (dikirim ulang atas permintaan pendaftar yang e-mailnya sudah terverifikasi
 * tapi belum membayar). Link berlaku sampai batas bayar (members.payment_due_at).
 */
class MemberRegistrationPaymentLink extends Notification implements ShouldQueue
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
        $fee = 'Rp'.number_format((int) config('membership.annual_fee'), 0, ',', '.');
        $deadline = $notifiable->payment_due_at?->timezone('Asia/Jakarta')->translatedFormat('d F Y H:i');

        return (new MailMessage)
            ->subject('Selesaikan pembayaran iuran pendaftaran KOLEKSI')
            ->greeting('Halo '.($notifiable->nama_panggilan ?: $notifiable->nama_lengkap).',')
            ->line("E-mail Anda sudah terverifikasi. Selesaikan pendaftaran dengan membayar iuran tahunan sebesar {$fee} melalui QRIS (semua e-wallet & mobile banking).")
            ->action('Bayar Iuran Pendaftaran', app(MemberRegistrationService::class)->paymentUrl($notifiable))
            ->line("Link ini berlaku sampai {$deadline} WIB. Setelah pembayaran berhasil, akun Anda langsung aktif dan mendapat No. anggota.")
            ->line('Kalau batas waktu terlewat, pendaftaran dihapus dan Anda bisa mendaftar ulang.');
    }
}
