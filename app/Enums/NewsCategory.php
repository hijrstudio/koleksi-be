<?php

namespace App\Enums;

enum NewsCategory: string
{
    case NewsRelease = 'news_release';
    case EvNews = 'ev_news';
    case Coverage = 'coverage';

    /**
     * Label sesuai locale (default Indonesia), untuk CMS & website.
     */
    public function label(?string $locale = 'id'): string
    {
        return match ($this) {
            self::NewsRelease => 'News Release',
            self::EvNews => $locale === 'en' ? 'EV News' : 'Berita EV',
            self::Coverage => $locale === 'en' ? 'Coverage' : 'Liputan',
        };
    }

    /**
     * Class badge TailAdmin untuk tiap kategori.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::NewsRelease => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
            self::EvNews => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Coverage => 'bg-blue-light-50 text-blue-light-600 dark:bg-blue-light-500/15 dark:text-blue-light-500',
        };
    }
}
