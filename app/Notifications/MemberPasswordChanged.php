<?php

namespace App\Notifications;

use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Pemberitahuan keamanan setelah kata sandi member diganti — supaya member sadar kalau
 * yang mengganti bukan dirinya. Isinya sengaja tidak memuat kata sandi atau link login.
 */
class MemberPasswordChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Carbon $changedAt,
        public readonly ?string $ip,
    ) {}

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
            ->subject('Kata sandi akun KOLEKSI Anda telah diubah')
            ->greeting('Halo '.($notifiable->nama_panggilan ?: $notifiable->nama_lengkap).',')
            ->line($this->message())
            ->line('Semua sesi login di perangkat lain telah dikeluarkan.')
            ->line('Kalau Anda tidak merasa mengganti kata sandi, segera hubungi pengurus KOLEKSI.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Member $notifiable): array
    {
        return [
            'type' => 'account.password_changed',
            'title' => 'Kata sandi diubah',
            'message' => $this->message(),
            'changed_at' => $this->changedAt->toIso8601String(),
            'ip' => $this->ip,
        ];
    }

    private function message(): string
    {
        $time = $this->changedAt->copy()->timezone('Asia/Jakarta')->locale('id')->translatedFormat('d F Y, H:i').' WIB';

        return "Kata sandi akun KOLEKSI Anda diubah pada {$time}".($this->ip ? " (IP {$this->ip})." : '.');
    }
}
