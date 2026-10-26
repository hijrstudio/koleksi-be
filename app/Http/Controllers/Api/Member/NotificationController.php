<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemberNotificationResource;
use App\Models\Member;
use App\Notifications\MembershipExpired;
use App\Notifications\MembershipRenewalReminder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lonceng notifikasi di website (tabel `notifications`, milik member yang login).
 *
 * Pengingat daftar ulang & "keanggotaan tidak aktif" sengaja TIDAK ditampilkan di sini: website menampilkan status
 * iuran langsung dari `billing` di /member/me (selalu terbaru), jadi notifikasi tersimpan itu akan dobel/kedaluwarsa.
 * E-mail-nya tetap terkirim seperti biasa.
 */
class NotificationController extends Controller
{
    private const LIMIT = 20;

    /** Tipe yang sudah diwakili status `billing` di /member/me. */
    private const LIVE_TYPES = [MembershipRenewalReminder::class, MembershipExpired::class];

    public function index(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $notifications = $this->visible($member->notifications())->limit(self::LIMIT)->get();

        return response()->json([
            'data' => MemberNotificationResource::collection($notifications),
            'meta' => ['unread_count' => $this->unreadCount($member)],
        ]);
    }

    /** Tandai dibaca: satu notifikasi (`id`) atau semua kalau `id` kosong. */
    public function markAsRead(Request $request): JsonResponse
    {
        $validated = $request->validate(['id' => ['nullable', 'uuid']]);
        $member = $this->member($request);

        $query = $this->visible($member->unreadNotifications());
        if (filled($validated['id'] ?? null)) {
            $query->whereKey($validated['id']);
        }
        $query->update(['read_at' => now()]);

        return response()->json(['meta' => ['unread_count' => $this->unreadCount($member)]]);
    }

    private function member(Request $request): Member
    {
        /** @var Member */
        return $request->user();
    }

    private function visible(MorphMany $query): MorphMany
    {
        return $query->whereNotIn('type', self::LIVE_TYPES);
    }

    private function unreadCount(Member $member): int
    {
        return $this->visible($member->unreadNotifications())->count();
    }
}
