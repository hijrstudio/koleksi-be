<?php

use App\Enums\ContentStatus;
use App\Models\GuidePlaybook;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(GuidePlaybook::COVER_DISK);
    Storage::fake(GuidePlaybook::PDF_DISK);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

function playbookPayload(array $overrides = []): array
{
    return array_merge([
        'title' => ['id' => 'Panduan Memilih EV', 'en' => 'Choosing an EV'],
        'cover_image' => UploadedFile::fake()->image('cover.png', 540, 640),
        'file_pdf' => UploadedFile::fake()->create('playbook.pdf', 300, 'application/pdf'),
        'status' => 'published',
    ], $overrides);
}

test('guest cannot access web playbooks in the CMS', function () {
    $this->get(route('guide-playbooks.index'))->assertRedirect(route('signin'));
});

test('index lists playbooks and supports search & status filters', function () {
    GuidePlaybook::factory()->published()->create(['title' => ['id' => 'Charging 101', 'en' => 'Charging basics']]);
    GuidePlaybook::factory()->create(['title' => ['id' => 'Perawatan EV', 'en' => 'EV Maintenance']]);

    $this->actingAs($this->admin)->get(route('guide-playbooks.index'))
        ->assertOk()->assertSee('Charging 101')->assertSee('Perawatan EV')->assertSee('Tambah Data');

    $this->actingAs($this->admin)->get(route('guide-playbooks.index', ['search' => 'basics']))
        ->assertSee('Charging 101')->assertDontSee('Perawatan EV');

    $this->actingAs($this->admin)->get(route('guide-playbooks.index', ['status' => 'draft']))
        ->assertSee('Perawatan EV')->assertDontSee('Charging 101');
});

test('create stores the cover on the public disk and the PDF on the private disk', function () {
    $this->actingAs($this->admin)->get(route('guide-playbooks.create'))->assertOk();

    $this->actingAs($this->admin)->post(route('guide-playbooks.store'), playbookPayload(['title' => ['id' => '  Charging 101  ', 'en' => '']]))
        ->assertRedirect(route('guide-playbooks.index'));

    $playbook = GuidePlaybook::sole();
    expect($playbook->title)->toBe(['id' => 'Charging 101', 'en' => null])
        ->and($playbook->status)->toBe(ContentStatus::Published)
        ->and($playbook->created_by)->toBe($this->admin->id);

    Storage::disk(GuidePlaybook::COVER_DISK)->assertExists($playbook->cover_image);
    Storage::disk(GuidePlaybook::PDF_DISK)->assertExists($playbook->file_pdf);
    Storage::disk(GuidePlaybook::COVER_DISK)->assertMissing($playbook->file_pdf);
});

test('update keeps files when none are uploaded and replaces only the uploaded one', function () {
    $this->actingAs($this->admin)->post(route('guide-playbooks.store'), playbookPayload());
    $playbook = GuidePlaybook::sole();
    [$cover, $pdf] = [$playbook->cover_image, $playbook->file_pdf];

    $this->actingAs($this->admin)->put(route('guide-playbooks.update', $playbook), playbookPayload([
        'title' => ['id' => 'Judul Baru', 'en' => 'New Title'], 'cover_image' => null, 'file_pdf' => null, 'status' => 'draft',
    ]))->assertRedirect(route('guide-playbooks.index'));

    expect($playbook->fresh()->cover_image)->toBe($cover)->and($playbook->fresh()->file_pdf)->toBe($pdf)
        ->and($playbook->fresh()->status)->toBe(ContentStatus::Draft);

    $this->actingAs($this->admin)->put(route('guide-playbooks.update', $playbook), playbookPayload(['cover_image' => null]));

    expect($playbook->fresh()->cover_image)->toBe($cover)->and($playbook->fresh()->file_pdf)->not->toBe($pdf);
    Storage::disk(GuidePlaybook::PDF_DISK)->assertMissing($pdf);
    Storage::disk(GuidePlaybook::COVER_DISK)->assertExists($cover);
});

test('validation rejects invalid input', function (Closure $overrides, string $errorField) {
    $this->actingAs($this->admin)->post(route('guide-playbooks.store'), playbookPayload($overrides()))
        ->assertSessionHasErrors($errorField);

    expect(GuidePlaybook::count())->toBe(0);
})->with([
    'title id required' => [fn () => ['title' => ['id' => '', 'en' => 'x']], 'title.id'],
    'cover required on create' => [fn () => ['cover_image' => null], 'cover_image'],
    'pdf required on create' => [fn () => ['file_pdf' => null], 'file_pdf'],
    'pdf must be a pdf' => [fn () => ['file_pdf' => UploadedFile::fake()->create('virus.pdf', 10, 'application/x-msdownload')], 'file_pdf'],
    'pdf max size' => [fn () => ['file_pdf' => UploadedFile::fake()->create('besar.pdf', 25 * 1024, 'application/pdf')], 'file_pdf'],
    'svg cover not allowed' => [fn () => ['cover_image' => UploadedFile::fake()->create('cover.svg', 5, 'image/svg+xml')], 'cover_image'],
    'invalid status' => [fn () => ['status' => 'archived'], 'status'],
]);

test('admin can preview the private PDF, guests cannot', function () {
    $this->actingAs($this->admin)->post(route('guide-playbooks.store'), playbookPayload());
    $playbook = GuidePlaybook::sole();

    $this->actingAs($this->admin)->get(route('guide-playbooks.pdf', $playbook))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    auth()->logout();
    $this->get(route('guide-playbooks.pdf', $playbook))->assertRedirect(route('signin'));
});

test('delete removes the playbook, its cover and its PDF', function () {
    $this->actingAs($this->admin)->post(route('guide-playbooks.store'), playbookPayload());
    $playbook = GuidePlaybook::sole();

    $this->actingAs($this->admin)->delete(route('guide-playbooks.destroy', $playbook))->assertRedirect(route('guide-playbooks.index'));

    expect(GuidePlaybook::count())->toBe(0);
    Storage::disk(GuidePlaybook::COVER_DISK)->assertMissing($playbook->cover_image);
    Storage::disk(GuidePlaybook::PDF_DISK)->assertMissing($playbook->file_pdf);
});

test('policy follows guide-playbooks.* permissions for non super admin roles', function () {
    $editor = User::factory()->create();
    $playbook = GuidePlaybook::factory()->create();

    expect($editor->can('viewAny', GuidePlaybook::class))->toBeFalse();

    $editor->givePermissionTo('guide-playbooks.view', 'guide-playbooks.update');

    expect($editor->can('viewAny', GuidePlaybook::class))->toBeTrue()
        ->and($editor->can('update', $playbook))->toBeTrue()
        ->and($editor->can('create', GuidePlaybook::class))->toBeFalse()
        ->and($editor->can('delete', $playbook))->toBeFalse();
});
