<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * Fase event untuk tab website (On Plan / On Going / Done).
 * TIDAK disimpan di database: selalu dihitung dari start_date & end_date terhadap hari ini,
 * sehingga tidak pernah basi dan tidak butuh cron untuk memperbaruinya.
 */
enum EventPhase: string
{
    case Upcoming = 'upcoming'; // On Plan: belum mulai
    case Ongoing = 'ongoing';   // On Going: hari ini di antara start & end
    case Done = 'done';         // Done: sudah lewat end_date

    public static function for(Carbon $start, Carbon $end, ?Carbon $today = null): self
    {
        $today = ($today ?? now())->copy()->startOfDay();

        return match (true) {
            $start->copy()->startOfDay()->gt($today) => self::Upcoming,
            $end->copy()->startOfDay()->lt($today) => self::Done,
            default => self::Ongoing,
        };
    }

    public function label(?string $locale = 'id'): string
    {
        return match ($this) {
            self::Upcoming => $locale === 'en' ? 'On Plan' : 'Akan Datang',
            self::Ongoing => $locale === 'en' ? 'On Going' : 'Berlangsung',
            self::Done => $locale === 'en' ? 'Done' : 'Selesai',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Upcoming => 'bg-blue-light-50 text-blue-light-600 dark:bg-blue-light-500/15 dark:text-blue-light-500',
            self::Ongoing => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Done => 'bg-gray-100 text-gray-600 dark:bg-white/[0.05] dark:text-gray-400',
        };
    }
}
