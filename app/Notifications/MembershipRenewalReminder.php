<?php

namespace App\Notifications;

use App\Models\Member;
use App\Support\MembershipTerm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Pengingat daftar ulang ke member (H-30, H-7, hari-H).
 * Disimpan di tabel `notifications` (ditampilkan di aplikasi member) + email kalau member punya email.
 */
class MembershipRenewalReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly MembershipTerm $renewalTerm,
        public readonly int $daysLeft,
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
            ->subject($this->title())
            ->greeting('Halo '.($notifiable->nama_panggilan ?: $notifiable->nama_lengkap).',')
            ->line($this->message($notifiable))
            ->line('Periode keanggotaan baru: '.$this->periodText().'.')
            ->action('Daftar Ulang Sekarang', config('membership.renewal_url'))
            ->line('Terima kasih telah menjadi bagian dari KOLEKSI.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Member $notifiable): array
    {
        return [
            'type' => 'membership.renewal_reminder',
            'title' => $this->title(),
            'message' => $this->message($notifiable),
            'period_label' => $this->renewalTerm->label(),
            'period_start' => $this->renewalTerm->start->toDateString(),
            'period_end' => $this->renewalTerm->end->toDateString(),
            'renewal_date' => $this->renewalTerm->start->toDateString(),
            'active_until' => $notifiable->active_until?->toDateString(),
            'days_left' => $this->daysLeft,
            'action_url' => config('membership.renewal_url'),
        ];
    }

    private function title(): string
    {
        return $this->daysLeft > 0
            ? "Daftar ulang keanggotaan dalam {$this->daysLeft} hari"
            : 'Saatnya daftar ulang keanggotaan KOLEKSI';
    }

    private function message(Member $member): string
    {
        $due = $this->renewalTerm->start->translatedFormat('d F Y');
        $until = $member->active_until?->translatedFormat('d F Y');

        return $this->daysLeft > 0
            ? "Keanggotaan Anda ({$member->noLambungLabel()}) jatuh tempo daftar ulang pada {$due}. Silakan daftar ulang untuk periode {$this->renewalTerm->label()}."
            : "Keanggotaan Anda ({$member->noLambungLabel()}) sudah jatuh tempo daftar ulang. Daftar ulang sebelum {$until} agar status keanggotaan tetap aktif.";
    }

    private function periodText(): string
    {
        return $this->renewalTerm->start->translatedFormat('d M Y').' – '.$this->renewalTerm->end->translatedFormat('d M Y');
    }
}
