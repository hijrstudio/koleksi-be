<?php

namespace App\Notifications;

use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Dikirim saat status member berubah jadi tidak aktif karena belum daftar ulang
 * sampai masa tenggang habis.
 */
class MembershipExpired extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(Member $notifiable): array
    {
        return filled($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Keanggotaan KOLEKSI Anda tidak aktif')
            ->greeting('Halo '.($notifiable->nama_panggilan ?: $notifiable->nama_lengkap).',')
            ->line($this->message($notifiable))
            ->action('Daftar Ulang', config('membership.renewal_url'))
            ->line('Keanggotaan akan aktif kembali setelah daftar ulang selesai.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Member $notifiable): array
    {
        return [
            'type' => 'membership.expired',
            'title' => 'Keanggotaan tidak aktif',
            'message' => $this->message($notifiable),
            'paid_until' => $notifiable->paid_until?->toDateString(),
            'active_until' => $notifiable->active_until?->toDateString(),
            'action_url' => config('membership.renewal_url'),
        ];
    }

    private function message(Member $member): string
    {
        $paidUntil = $member->paid_until?->translatedFormat('d F Y') ?? '-';

        return "Keanggotaan Anda ({$member->noLambungLabel()}) berakhir pada {$paidUntil} dan belum didaftarkan ulang, sehingga status Anda sekarang tidak aktif.";
    }
}
