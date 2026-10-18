<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Satu periode keanggotaan (1 tahun) milik seorang member.
 *
 *   anchor 10 Feb 2026, tahun 2026 → 10 Feb 2026 – 9 Feb 2027, label "2026-2027"
 *   anchor 29 Feb 2024, tahun 2025 → 28 Feb 2025 – 27 Feb 2026 (tidak overflow ke Maret)
 */
final readonly class MembershipTerm
{
    public function __construct(
        public Carbon $start,
        public Carbon $end,
    ) {}

    /** Periode yang dimulai pada ulang tahun keanggotaan di tahun $year. */
    public static function forYear(Carbon $anchor, int $year): self
    {
        $start = $anchor->copy()->startOfDay()->addYearsNoOverflow($year - $anchor->year);

        return new self($start, $start->copy()->addYearNoOverflow()->subDay());
    }

    /** Periode yang mencakup tanggal $date (sebelum anchor → periode pertama). */
    public static function containing(Carbon $anchor, Carbon $date): self
    {
        $term = self::forYear($anchor, max($anchor->year, $date->year));

        // Ulang tahun keanggotaan di tahun ini belum lewat → masih periode tahun lalu
        if ($date->lt($term->start) && $term->start->year > $anchor->year) {
            $term = self::forYear($anchor, $term->start->year - 1);
        }

        return $term;
    }

    /** Periode berikutnya (dimulai sehari setelah periode ini berakhir). */
    public function next(Carbon $anchor): self
    {
        return self::forYear($anchor, $this->start->year + 1);
    }

    /** "2026-2027" */
    public function label(): string
    {
        return $this->start->year.'-'.($this->start->year + 1);
    }

    /** Tanggal daftar ulang jatuh tempo = hari pertama periode berikutnya. */
    public function renewalDate(): Carbon
    {
        return $this->end->copy()->addDay();
    }

    public function contains(Carbon $date): bool
    {
        return $date->betweenIncluded($this->start, $this->end);
    }
}
