<?php

use App\Models\Member;
use App\Notifications\MemberRegistrationActivated;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('emails show the KOLEKSI logo linking to the website, in Indonesian', function () {
    config([
        'mail.site_url' => 'https://koleksi.club',
        'mail.logo_url' => 'https://koleksi.club/images/logo.png',
        'app.name' => 'KOLEKSI',
    ]);
    $member = Member::factory()->create(['no_lambung' => 7, 'nama_panggilan' => 'Budi']);

    $html = (string) (new MemberRegistrationActivated)->toMail($member)->render();

    expect($html)
        ->toContain('<a href="https://koleksi.club"')
        ->toContain('src="https://koleksi.club/images/logo.png"')
        ->toContain('alt="KOLEKSI"')
        ->toContain('Pengurus KOLEKSI')
        ->toContain('Komunitas Mobil Elektrik Indonesia')
        ->not->toContain('Regards')
        ->not->toContain('laravel.com');
});
