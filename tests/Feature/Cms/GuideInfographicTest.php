<?php

use App\Enums\ContentStatus;
use App\Models\GuideCategory;
use App\Models\GuideInfographic;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(GuideInfographic::DISK);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
    $this->category = GuideCategory::factory()->published()->create();
});

function infographicPayload(array $overrides = []): array
{
    return array_merge([
        'guide_category_id' => test()->category->id,
        'title' => ['id' => 'Jenis Konektor Pengisian Daya', 'en' => 'EV Charging Connector Types'],
        'image' => UploadedFile::fake()->image('infografis.png', 1080, 3000),
        'status' => 'published',
    ], $overrides);
}

test('guest cannot access guide infographics', function () {
    $this->get(route('guide-infographics.index'))->assertRedirect(route('signin'));
});

test('index lists infographics and supports search, category & status filters', function () {
    $other = GuideCategory::factory()->create();
    GuideInfographic::factory()->published()->for($this->category, 'category')
        ->create(['title' => ['id' => 'Hemat Baterai', 'en' => 'Battery Saving']]);
    GuideInfographic::factory()->for($other, 'category')
        ->create(['title' => ['id' => 'Tekanan Ban', 'en' => 'Tyre Pressure']]);

    $this->actingAs($this->admin)->get(route('guide-infographics.index'))
        ->assertOk()->assertSee('Hemat Baterai')->assertSee('Tekanan Ban');

    $this->actingAs($this->admin)->get(route('guide-infographics.index', ['search' => 'Tyre']))
        ->assertOk()->assertSee('Tekanan Ban')->assertDontSee('Hemat Baterai');

    $this->actingAs($this->admin)->get(route('guide-infographics.index', ['category' => $other->id]))
        ->assertOk()->assertSee('Tekanan Ban')->assertDontSee('Hemat Baterai');

    $this->actingAs($this->admin)->get(route('guide-infographics.index', ['status' => 'published']))
        ->assertOk()->assertSee('Hemat Baterai')->assertDontSee('Tekanan Ban');
});

test('super admin can create an infographic', function () {
    $this->actingAs($this->admin)->get(route('guide-infographics.create'))->assertOk();

    $this->actingAs($this->admin)->post(route('guide-infographics.store'), infographicPayload())
        ->assertRedirect(route('guide-infographics.index'))->assertSessionHas('success');

    $infographic = GuideInfographic::sole();

    expect($infographic->title)->toBe(['id' => 'Jenis Konektor Pengisian Daya', 'en' => 'EV Charging Connector Types'])
        ->and($infographic->status)->toBe(ContentStatus::Published)
        ->and($infographic->image)->toStartWith(GuideInfographic::DIRECTORY.'/'.now()->format('Y/m').'/')
        ->and($infographic->created_by)->toBe($this->admin->id)
        ->and($infographic->toArray())->toHaveKey('image_url');

    Storage::disk(GuideInfographic::DISK)->assertExists($infographic->image);
});

test('update keeps the image when none is uploaded and replaces it otherwise', function () {
    $this->actingAs($this->admin)->post(route('guide-infographics.store'), infographicPayload());
    $infographic = GuideInfographic::sole();
    $first = $infographic->image;

    $this->actingAs($this->admin)->get(route('guide-infographics.edit', $infographic))->assertOk();

    $this->actingAs($this->admin)->put(route('guide-infographics.update', $infographic), infographicPayload([
        'image' => null,
        'title' => ['id' => 'Judul Baru', 'en' => ''],
    ]))->assertSessionHasNoErrors();

    expect($infographic->fresh())
        ->image->toBe($first)
        ->title->toBe(['id' => 'Judul Baru', 'en' => null]);

    $this->actingAs($this->admin)->put(route('guide-infographics.update', $infographic), infographicPayload([
        'image' => UploadedFile::fake()->image('baru.webp', 1080, 1920),
    ]))->assertSessionHasNoErrors();

    $second = $infographic->fresh()->image;
    expect($second)->not->toBe($first);
    Storage::disk(GuideInfographic::DISK)->assertMissing($first);
    Storage::disk(GuideInfographic::DISK)->assertExists($second);
});

test('validation rejects invalid input', function (Closure $overrides, string $errorField) {
    $this->actingAs($this->admin)->post(route('guide-infographics.store'), infographicPayload($overrides()))
        ->assertSessionHasErrors($errorField);

    expect(GuideInfographic::count())->toBe(0);
})->with([
    'missing category' => [fn () => ['guide_category_id' => null], 'guide_category_id'],
    'unknown category' => [fn () => ['guide_category_id' => 999], 'guide_category_id'],
    'missing title id' => [fn () => ['title' => ['id' => '', 'en' => 'T']], 'title.id'],
    'unknown locale key' => [fn () => ['title' => ['id' => 'T', 'fr' => 'T']], 'title'],
    'missing image on create' => [fn () => ['image' => null], 'image'],
    'svg image' => [fn () => ['image' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')], 'image'],
    'pdf image' => [fn () => ['image' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')], 'image'],
    'image too large' => [fn () => ['image' => UploadedFile::fake()->image('big.png')->size(11 * 1024)], 'image'],
    'image too tall' => [fn () => ['image' => UploadedFile::fake()->image('tall.png', 1000, 10_001)], 'image'],
    'invalid status' => [fn () => ['status' => 'archived'], 'status'],
]);

test('delete removes the infographic and its image', function () {
    $this->actingAs($this->admin)->post(route('guide-infographics.store'), infographicPayload());
    $infographic = GuideInfographic::sole();

    $this->actingAs($this->admin)->delete(route('guide-infographics.destroy', $infographic))
        ->assertRedirect(route('guide-infographics.index'));

    expect(GuideInfographic::count())->toBe(0);
    Storage::disk(GuideInfographic::DISK)->assertMissing($infographic->image);
});

test('category that still has infographics cannot be deleted', function () {
    GuideInfographic::factory()->for($this->category, 'category')->create();

    $this->actingAs($this->admin)->from(route('guide-categories.index'))
        ->delete(route('guide-categories.destroy', $this->category))
        ->assertSessionHas('error');

    expect($this->category->fresh())->not->toBeNull();

    $this->actingAs($this->admin)->get(route('guide-categories.index'))->assertOk()->assertSee('1 infografis');
});

test('visible scope requires both infographic and category to be published', function () {
    $draftCategory = GuideCategory::factory()->create();
    GuideInfographic::factory()->published()->for($this->category, 'category')->create(['title' => ['id' => 'Tampil', 'en' => null]]);
    GuideInfographic::factory()->published()->for($draftCategory, 'category')->create(['title' => ['id' => 'Kategori draft', 'en' => null]]);
    GuideInfographic::factory()->for($this->category, 'category')->create(['title' => ['id' => 'Infografis draft', 'en' => null]]);

    expect(GuideInfographic::visible()->get()->map->titleFor('id')->all())->toBe(['Tampil']);
});

test('policy follows guide-infographics.* permissions for non super admin roles', function () {
    $editor = User::factory()->create();
    $infographic = GuideInfographic::factory()->create();

    expect($editor->can('viewAny', GuideInfographic::class))->toBeFalse();

    $editor->givePermissionTo('guide-infographics.view', 'guide-infographics.update');

    expect($editor->can('viewAny', GuideInfographic::class))->toBeTrue()
        ->and($editor->can('update', $infographic))->toBeTrue()
        ->and($editor->can('create', GuideInfographic::class))->toBeFalse()
        ->and($editor->can('delete', $infographic))->toBeFalse();
});
