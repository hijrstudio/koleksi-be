<?php

namespace App\Enums;

enum ContentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Publish',
        };
    }

    /**
     * Class badge TailAdmin untuk tiap status.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
            self::Published => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
        };
    }
}
