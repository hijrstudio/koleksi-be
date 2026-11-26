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

        $path = self::localPath((string) ($notification->data['action_url'] ?? ''));

        return $path ? redirect()->to($path) : redirect()->route('dashboard');
    }

    /**
     * Ambil path (+ query) dari action_url, dibuka di domain CMS yang sedang dipakai. action_url dibuat dengan APP_URL
     * (di production = domain API, bukan domain CMS), jadi domainnya diabaikan. Hanya path absolut di aplikasi ini
     * ("/members/12"), bukan "//domain-lain" → tidak bisa dipakai untuk open redirect.
     */
    public static function localPath(string $url): ?string
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';

        if ($parts === false || ! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
