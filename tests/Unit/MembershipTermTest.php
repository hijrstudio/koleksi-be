<?php

use App\Support\MembershipTerm;
use Illuminate\Support\Carbon;

test('term starts on the membership anniversary', function () {
    $term = MembershipTerm::forYear(Carbon::parse('2021-08-08'), 2026);

    expect($term->label())->toBe('2026-2027')
        ->and($term->start->toDateString())->toBe('2026-08-08')
        ->and($term->end->toDateString())->toBe('2027-08-07')
        ->and($term->renewalDate()->toDateString())->toBe('2027-08-08');
});

test('containing picks last year term before this year anniversary', function (string $date, string $expectedStart) {
    $term = MembershipTerm::containing(Carbon::parse('2022-02-15'), Carbon::parse($date));

    expect($term->start->toDateString())->toBe($expectedStart);
})->with([
    'sebelum ulang tahun' => ['2026-02-14', '2025-02-15'],
    'tepat ulang tahun' => ['2026-02-15', '2026-02-15'],
    'setelah ulang tahun' => ['2026-10-08', '2026-02-15'],
    'sebelum tanggal bergabung' => ['2021-01-01', '2022-02-15'],
]);

test('29 february anniversary does not overflow into march', function () {
    $term = MembershipTerm::forYear(Carbon::parse('2024-02-29'), 2025);

    expect($term->start->toDateString())->toBe('2025-02-28')
        ->and($term->end->toDateString())->toBe('2026-02-27');
});
