<?php

namespace App\Notifications;

use App\Models\Member;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Ke pengurus (admin CMS): ada pendaftar baru yang e-mailnya sudah terverifikasi dan menunggu review.
 * Admin CMS → lonceng notifikasi CMS + e-mail; alamat di membership.registration.admin_emails → e-mail saja.
 */
class NewMemberRegistration extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Member $member) {}

    /**
     * @return list<string>
     */
    public function via(User|AnonymousNotifiable $notifiable): array
    {
        return $notifiable instanceof User ? ['database', 'mail'] : ['mail'];
    }

    public function toMail(User|AnonymousNotifiable $notifiable): MailMessage
    {
        $m = $this->member;
        $vehicle = $m->primaryVehicle();

        return (new MailMessage)
            ->subject('Pendaftar baru menunggu review: '.$m->nama_lengkap)
            ->greeting('Halo Pengurus KOLEKSI,')
            ->line('Ada pendaftar baru yang sudah memverifikasi e-mail dan menunggu review:')
            ->line('Nama: '.$m->nama_lengkap)
            ->line('E-mail: '.$m->email)
            ->line('No. HP: '.$m->no_hp)
            ->line('Kendaraan: '.($vehicle ? trim($vehicle->plat_display.' '.$vehicle->displayName()) : 'Tidak memiliki kendaraan'))
            ->action('Review Pendaftaran', route('member-registrations.show', $m))
            ->line('Setelah disetujui, pendaftar akan menerima e-mail untuk membayar iuran pertama.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'type' => 'member.registration_pending',
            'title' => 'Pendaftar baru',
            'message' => $this->member->nama_lengkap.' menunggu review pendaftaran.',
            'member_id' => $this->member->id,
            'action_url' => route('member-registrations.show', $this->member),
        ];
    }
}
