<?php

namespace App\View\Components\Header;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/** Lonceng notifikasi CMS: notifikasi terbaru milik admin yang login (mis. pendaftar baru menunggu review). */
class NotificationDropdown extends Component
{
    private const LIMIT = 10;

    /** @var Collection<int, DatabaseNotification> */
    public Collection $notifications;

    public int $unreadCount = 0;

    public function __construct()
    {
        $user = auth()->user();

        $this->notifications = $user ? $user->notifications()->latest()->limit(self::LIMIT)->get() : collect();
        $this->unreadCount = $user ? $user->unreadNotifications()->count() : 0;
    }

    public function render(): View|Closure|string
    {
        return view('components.header.notification-dropdown');
    }
}
