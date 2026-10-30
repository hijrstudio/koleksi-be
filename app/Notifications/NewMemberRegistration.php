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
 * Ke pengurus (admin CMS): ada anggota baru yang mendaftar lewat website dan sudah membayar iuran pertama.
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
            ->subject('Anggota baru: '.$m->nama_lengkap.' ('.$m->noLambungLabel().')')
            ->greeting('Halo Pengurus KOLEKSI,')
            ->line('Ada anggota baru yang mendaftar lewat website dan sudah membayar iuran pertama:')
            ->line('No. lambung: '.$m->noLambungLabel())
            ->line('Nama: '.$m->nama_lengkap)
            ->line('E-mail: '.$m->email)
            ->line('No. HP: '.$m->no_hp)
            ->line('Kendaraan: '.($vehicle ? trim($vehicle->plat_display.' '.$vehicle->displayName()) : 'Tidak memiliki kendaraan'))
            ->action('Lihat Anggota', route('members.show', $m))
            ->line('Data kendaraan & dokumen (kalau ada) bisa diperiksa di halaman detail anggota.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'type' => 'member.registration_activated',
            'title' => 'Anggota baru',
            'message' => $this->member->nama_lengkap.' ('.$this->member->noLambungLabel().') bergabung dan sudah membayar iuran.',
            'member_id' => $this->member->id,
            'action_url' => route('members.show', $this->member),
        ];
    }
}
