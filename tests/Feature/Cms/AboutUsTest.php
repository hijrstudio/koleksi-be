<?php

use App\Models\AboutUs;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(AboutUs::DISK);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

function aboutUsPayload(array $overrides = []): array
{
    return array_merge([
        'summary' => ['id' => '<p>Ringkasan <strong>komunitas</strong></p>', 'en' => '<p>Community summary</p>'],
        'profil_organisasi' => ['id' => '<p>Profil</p>', 'en' => null],
        'visi' => ['id' => 'Menjadi komunitas terbaik', 'en' => 'To be the best community'],
        'misi' => ['id' => '<ol><li><p>Satu</p></li></ol>', 'en' => ''],
        'syarat_anggota' => ['id' => '<ul><li><p>KTP</p></li></ul>', 'en' => '<ul><li><p>ID card</p></li></ul>'],
        'daftar_ulang_anggota' => ['id' => null, 'en' => null],
    ], $overrides);
}

test('guest cannot access about us', function () {
    $this->get(route('about-us.edit'))->assertRedirect(route('signin'));
});

test('menu opens the form directly even when no data exists yet', function () {
    $this->actingAs($this->admin)->get(route('about-us.edit'))
        ->assertOk()
        ->assertSee('belum pernah diisi');
});

test('first save creates the single row with files', function () {
    $this->actingAs($this->admin)->put(route('about-us.update'), aboutUsPayload([
        'image' => UploadedFile::fake()->image('about.jpg', 1200, 800),
        'aturan_ad_art' => UploadedFile::fake()->create('adart.pdf', 500, 'application/pdf'),
    ]))->assertRedirect(route('about-us.edit'))->assertSessionHas('success');

    $about = AboutUs::sole();

    expect($about->id)->toBe(AboutUs::SINGLETON_ID)
        ->and($about->summary)->toBe(['id' => '<p>Ringkasan <strong>komunitas</strong></p>', 'en' => '<p>Community summary</p>'])
        ->and($about->profil_organisasi)->toBe(['id' => '<p>Profil</p>', 'en' => null])
        ->and($about->daftar_ulang_anggota)->toBeNull()
        ->and($about->created_by)->toBe($this->admin->id);

    Storage::disk(AboutUs::DISK)->assertExists([$about->image, $about->aturan_ad_art]);
});

test('saving again updates the same row instead of creating a new one', function () {
    $this->actingAs($this->admin)->put(route('about-us.update'), aboutUsPayload());
    $this->actingAs($this->admin)->put(route('about-us.update'), aboutUsPayload([
        'visi' => ['id' => 'Visi baru', 'en' => null],
    ]));

    expect(AboutUs::count())->toBe(1)
        ->and(AboutUs::sole()->visi)->toBe(['id' => 'Visi baru', 'en' => null]);

    $this->actingAs($this->admin)->get(route('about-us.edit'))
        ->assertOk()
        ->assertSee('Visi baru')
        ->assertDontSee('belum pernah diisi');
});

test('replacing or removing a file deletes the old file', function () {
    $this->actingAs($this->admin)->put(route('about-us.update'), aboutUsPayload([
        'image' => UploadedFile::fake()->image('old.jpg'),
        'sponsor_doc' => UploadedFile::fake()->create('sponsor.pdf', 100, 'application/pdf'),
    ]));
    $old = AboutUs::sole();

    $this->actingAs($this->admin)->put(route('about-us.update'), aboutUsPayload([
        'image' => UploadedFile::fake()->image('new.png'),
        'remove_sponsor_doc' => '1',
    ]))->assertSessionHasNoErrors();

    $new = AboutUs::sole();

    expect($new->image)->not->toBe($old->image)
        ->and($new->sponsor_doc)->toBeNull();

    Storage::disk(AboutUs::DISK)->assertMissing([$old->image, $old->sponsor_doc]);
    Storage::disk(AboutUs::DISK)->assertExists($new->image);
});

test('rich text is sanitized against xss', function () {
    $this->actingAs($this->admin)->put(route('about-us.update'), aboutUsPayload([
        'summary' => [
            'id' => '<p onclick="alert(1)">Halo<script>alert(1)</script> <a href="javascript:alert(1)">x</a> <a href="https://example.com">ok</a></p><iframe src="https://evil.test"></iframe>',
            'en' => null,
        ],
    ]))->assertSessionHasNoErrors();

    $html = AboutUs::sole()->summary['id'];

    expect($html)->not->toContain('script')
        ->not->toContain('onclick')
        ->not->toContain('javascript:')
        ->not->toContain('iframe')
        ->toContain('<a href="https://example.com" rel="noopener noreferrer nofollow">ok</a>');
});

test('validation rejects empty summary, unknown locales and disallowed files', function (array $overrides, string $errorField) {
    $this->actingAs($this->admin)->put(route('about-us.update'), aboutUsPayload($overrides))
        ->assertSessionHasErrors($errorField);

    expect(AboutUs::count())->toBe(0);
})->with([
    'empty editor summary' => [['summary' => ['id' => '<p></p>', 'en' => null]], 'summary.id'],
    'script-only summary' => [['summary' => ['id' => '<script>alert(1)</script>', 'en' => null]], 'summary.id'],
    'unknown locale key' => [['visi' => ['id' => 'a', 'fr' => 'b']], 'visi'],
    'svg image' => [['image' => UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml')], 'image'],
    'ad/art not pdf' => [['aturan_ad_art' => UploadedFile::fake()->create('adart.docx', 10)], 'aturan_ad_art'],
    'sponsor exe' => [['sponsor_doc' => UploadedFile::fake()->create('virus.exe', 10)], 'sponsor_doc'],
    'image too large' => [['image' => UploadedFile::fake()->image('big.jpg')->size(6 * 1024)], 'image'],
]);

test('policy follows about-us.* permissions for non super admin roles', function () {
    $editor = User::factory()->create();

    expect($editor->can('view', AboutUs::class))->toBeFalse()
        ->and($editor->can('update', AboutUs::class))->toBeFalse();

    $editor->givePermissionTo('about-us.view');

    expect($editor->can('view', AboutUs::class))->toBeTrue()
        ->and($editor->can('update', AboutUs::class))->toBeFalse();
});
