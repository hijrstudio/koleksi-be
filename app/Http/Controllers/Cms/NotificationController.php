<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Lonceng notifikasi di header CMS (tabel `notifications`, milik admin yang login). */
class NotificationController extends Controller
{
    /** Klik notifikasi → tandai dibaca lalu buka halamannya. */
    public function open(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $url = (string) ($notification->data['action_url'] ?? '');

        // Hanya URL di aplikasi ini (cegah open redirect lewat isi notifikasi)
        return str_starts_with($url, url('/').'/') ? redirect()->to($url) : redirect()->route('dashboard');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
