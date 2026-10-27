<?php

use App\Enums\ContentStatus;
use App\Models\GuideRegulation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(GuideRegulation::PDF_DISK);
    Storage::fake('public');
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

function regulationPayload(array $overrides = []): array
{
    return array_merge([
        'title' => '  PERPRES NO. 55 TAHUN 2019 Percepatan Program KBLBB  ',
        'file_pdf' => UploadedFile::fake()->create('perpres.pdf', 300, 'application/pdf'),
        'status' => 'published',
    ], $overrides);
}

test('guest cannot access regulations', function () {
    $this->get(route('guide-regulations.index'))->assertRedirect(route('signin'));
});

test('index lists regulations and supports search & status filters', function () {
    GuideRegulation::factory()->published()->create(['title' => 'PERGUB NO. 41 TAHUN 2021 (NJKB)']);
    GuideRegulation::factory()->create(['title' => 'PERDA NO.1 TAHUN 2024 PDRB']);

    $this->actingAs($this->admin)->get(route('guide-regulations.index'))
        ->assertOk()->assertSee('Dokumen Regulasi')->assertSee('PERGUB NO. 41')->assertSee('PERDA NO.1');

    $this->actingAs($this->admin)->get(route('guide-regulations.index', ['search' => 'njkb']))
        ->assertSee('PERGUB NO. 41')->assertDontSee('PERDA NO.1');

    $this->actingAs($this->admin)->get(route('guide-regulations.index', ['status' => 'draft']))
        ->assertSee('PERDA NO.1')->assertDontSee('PERGUB NO. 41');
});

test('super admin can create a regulation with a private pdf', function () {
    $this->actingAs($this->admin)->get(route('guide-regulations.create'))->assertOk();

    $this->actingAs($this->admin)->post(route('guide-regulations.store'), regulationPayload())
        ->assertRedirect(route('guide-regulations.index'))->assertSessionHas('success');

    $regulation = GuideRegulation::sole();

    expect($regulation->title)->toBe('PERPRES NO. 55 TAHUN 2019 Percepatan Program KBLBB')
        ->and($regulation->status)->toBe(ContentStatus::Published)
        ->and($regulation->file_pdf)->toStartWith(GuideRegulation::DIRECTORY.'/')
        ->and($regulation->created_by)->toBe($this->admin->id);

    Storage::disk(GuideRegulation::PDF_DISK)->assertExists($regulation->file_pdf);
    Storage::disk('public')->assertMissing($regulation->file_pdf);

    $this->actingAs($this->admin)->get(route('guide-regulations.pdf', $regulation))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('update keeps the pdf when no new file is sent, and replaces it otherwise', function () {
    $this->actingAs($this->admin)->post(route('guide-regulations.store'), regulationPayload());
    $regulation = GuideRegulation::sole();
    $pdf = $regulation->file_pdf;

    $this->actingAs($this->admin)->get(route('guide-regulations.edit', $regulation))->assertOk()->assertSee('Lihat PDF saat ini');

    $this->actingAs($this->admin)->put(route('guide-regulations.update', $regulation), regulationPayload([
        'title' => 'Judul Baru', 'file_pdf' => null, 'status' => 'draft',
    ]))->assertSessionHasNoErrors();

    expect($regulation->fresh())
        ->title->toBe('Judul Baru')
        ->status->toBe(ContentStatus::Draft)
        ->file_pdf->toBe($pdf);

    $this->actingAs($this->admin)->put(route('guide-regulations.update', $regulation), regulationPayload())
        ->assertSessionHasNoErrors();

    expect($regulation->fresh()->file_pdf)->not->toBe($pdf);
    Storage::disk(GuideRegulation::PDF_DISK)->assertMissing($pdf);
    Storage::disk(GuideRegulation::PDF_DISK)->assertExists($regulation->fresh()->file_pdf);
});

test('validation rejects invalid input', function (Closure $overrides, string $errorField) {
    $this->actingAs($this->admin)->post(route('guide-regulations.store'), regulationPayload($overrides()))
        ->assertSessionHasErrors($errorField);

    expect(GuideRegulation::count())->toBe(0);
})->with([
    'missing title' => [fn () => ['title' => ''], 'title'],
    'title too long' => [fn () => ['title' => str_repeat('a', 256)], 'title'],
    'missing pdf' => [fn () => ['file_pdf' => null], 'file_pdf'],
    'not a pdf' => [fn () => ['file_pdf' => UploadedFile::fake()->image('scan.jpg')], 'file_pdf'],
    'pdf extension with other content' => [fn () => ['file_pdf' => UploadedFile::fake()->create('fake.pdf', 10, 'text/html')], 'file_pdf'],
    'pdf too large' => [fn () => ['file_pdf' => UploadedFile::fake()->create('big.pdf', 20 * 1024 + 1, 'application/pdf')], 'file_pdf'],
    'invalid status' => [fn () => ['status' => 'archived'], 'status'],
]);

test('delete removes the regulation and its pdf', function () {
    $this->actingAs($this->admin)->post(route('guide-regulations.store'), regulationPayload());
    $regulation = GuideRegulation::sole();

    $this->actingAs($this->admin)->delete(route('guide-regulations.destroy', $regulation))
        ->assertRedirect(route('guide-regulations.index'));

    expect(GuideRegulation::count())->toBe(0);
    Storage::disk(GuideRegulation::PDF_DISK)->assertMissing($regulation->file_pdf);
});

test('policy follows guide-regulations.* permissions for non super admin roles', function () {
    $editor = User::factory()->create();
    $regulation = GuideRegulation::factory()->create();

    $this->actingAs($editor)->get(route('guide-regulations.index'))->assertForbidden();
    $this->actingAs($editor)->get(route('guide-regulations.pdf', $regulation))->assertForbidden();

    $editor->givePermissionTo('guide-regulations.view', 'guide-regulations.update');

    expect($editor->can('viewAny', GuideRegulation::class))->toBeTrue()
        ->and($editor->can('update', $regulation))->toBeTrue()
        ->and($editor->can('create', GuideRegulation::class))->toBeFalse()
        ->and($editor->can('delete', $regulation))->toBeFalse();

    $this->actingAs($editor)->post(route('guide-regulations.store'), regulationPayload())->assertForbidden();
});
