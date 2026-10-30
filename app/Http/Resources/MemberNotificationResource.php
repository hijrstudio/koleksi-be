<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Arr;

/**
 * Notifikasi member untuk lonceng di website.
 *
 * Website menyusun teksnya sendiri dari `type` + `params` (supaya tampil sesuai bahasa ID/EN); `title` & `message`
 * (bahasa Indonesia, tersimpan saat notifikasi dibuat) dipakai kalau website belum mengenal tipenya.
 * `params` di-whitelist: isi `data` lain (mis. IP saat ganti kata sandi) tidak ikut dikirim.
 *
 * @mixin DatabaseNotification
 */
class MemberNotificationResource extends JsonResource
{
    private const PARAMS = ['changed_at', 'amount', 'period_label', 'renewal_date', 'active_until', 'days_left', 'no_lambung'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = (array) $this->data;

        return [
            'id' => $this->id,
            'type' => (string) ($data['type'] ?? ''),
            'title' => (string) ($data['title'] ?? ''),
            'message' => (string) ($data['message'] ?? ''),
            'params' => (object) Arr::only($data, self::PARAMS),
            'read' => $this->read_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
