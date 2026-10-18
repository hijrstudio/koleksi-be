<?php

namespace App\Notifications;

use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Pendaftaran ditolak pengurus. Pendaftar boleh mendaftar ulang dengan data yang diperbaiki. */
class MemberRegistrationRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $reason) {}

    /**
     * @return list<string>
     */
    public function via(Member $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Pendaftaran KOLEKSI Anda belum dapat disetujui')
            ->greeting('Halo '.($notifiable->nama_panggilan ?: $notifiable->nama_lengkap).',')
            ->line('Terima kasih atas minat Anda bergabung dengan KOLEKSI. Mohon maaf, pendaftaran Anda belum dapat kami setujui.')
            ->line('Alasan: '.$this->reason)
            ->line('Anda dapat mendaftar kembali dengan data yang sudah diperbaiki, atau menghubungi pengurus KOLEKSI bila ada pertanyaan.');
    }
}
