<?php

use App\Enums\ContentStatus;
use App\Models\SpkluOperator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(SpkluOperator::DISK);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

function spkluPayload(array $overrides = []): array
{
    return array_merge([
        'nama_operator' => 'PT PLN (Persero)',
        'brand_spklu' => 'PLN',
        'aplikasi' => 'PLN Mobile',
        'logo' => UploadedFile::fake()->image('logo.png', 400, 200),
        'link_playstore' => 'https://play.google.com/store/apps/details?id=com.icon.pln123',
        'link_appstore' => 'apps.apple.com/id/app/pln-mobile/id123',
        'status' => 'published',
    ], $overrides);
}

test('guest cannot access spklu operators', function () {
    $this->get(route('spklu-operators.index'))->assertRedirect(route('signin'));
});

test('user without permission is forbidden', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('spklu-operators.index'))->assertForbidden();
    $this->actingAs($user)->post(route('spklu-operators.store'), spkluPayload())->assertForbidden();

    expect(SpkluOperator::count())->toBe(0);
});

test('index lists operators by brand and supports search & status filter', function () {
    SpkluOperator::factory()->published()->create(['brand_spklu' => 'Voltron', 'nama_operator' => 'PT Voltron']);
    SpkluOperator::factory()->create(['brand_spklu' => 'Casion', 'nama_operator' => 'PT Casion Energi']);

    $this->actingAs($this->admin)->get(route('spklu-operators.index'))
        ->assertOk()->assertSeeInOrder(['Casion', 'Voltron']);

    $this->actingAs($this->admin)->get(route('spklu-operators.index', ['search' => 'casion energi']))
        ->assertOk()->assertSee('Casion')->assertDontSee('PT Voltron');

    $this->actingAs($this->admin)->get(route('spklu-operators.index', ['status' => 'published']))
        ->assertOk()->assertSee('Voltron')->assertDontSee('PT Casion Energi');
});

test('super admin can create an operator', function () {
    $this->actingAs($this->admin)->get(route('spklu-operators.create'))->assertOk();

    $this->actingAs($this->admin)->post(route('spklu-operators.store'), spkluPayload([
        'brand_spklu' => '  PLN  ',
    ]))->assertRedirect(route('spklu-operators.index'))->assertSessionHas('success');

    $operator = SpkluOperator::sole();

    expect($operator)
        ->brand_spklu->toBe('PLN')
        ->nama_operator->toBe('PT PLN (Persero)')
        ->aplikasi->toBe('PLN Mobile')
        ->link_appstore->toBe('https://apps.apple.com/id/app/pln-mobile/id123')
        ->status->toBe(ContentStatus::Published)
        ->created_by->toBe($this->admin->id)
        ->and($operator->logo)->toStartWith(SpkluOperator::DIRECTORY.'/'.now()->format('Y/m').'/');

    Storage::disk(SpkluOperator::DISK)->assertExists($operator->logo);
});

test('logo and store links are optional', function () {
    $this->actingAs($this->admin)->post(route('spklu-operators.store'), spkluPayload([
        'logo' => null, 'link_playstore' => '', 'link_appstore' => '',
    ]))->assertSessionHasNoErrors();

    expect(SpkluOperator::sole())
        ->logo->toBeNull()
        ->link_playstore->toBeNull()
        ->link_appstore->toBeNull();
});

test('update keeps, replaces or removes the logo', function () {
    $this->actingAs($this->admin)->post(route('spklu-operators.store'), spkluPayload());
    $operator = SpkluOperator::sole();
    $first = $operator->logo;

    $this->actingAs($this->admin)->get(route('spklu-operators.edit', $operator))->assertOk()->assertSee('PLN Mobile');

    $this->actingAs($this->admin)->put(route('spklu-operators.update', $operator), spkluPayload([
        'logo' => null, 'brand_spklu' => 'PLN', 'aplikasi' => 'PLN Mobile 2',
    ]))->assertSessionHasNoErrors();

    expect($operator->fresh())->logo->toBe($first)->aplikasi->toBe('PLN Mobile 2');

    $this->actingAs($this->admin)->put(route('spklu-operators.update', $operator), spkluPayload([
        'logo' => UploadedFile::fake()->image('baru.webp', 400, 200),
    ]))->assertSessionHasNoErrors();

    $second = $operator->fresh()->logo;
    expect($second)->not->toBe($first);
    Storage::disk(SpkluOperator::DISK)->assertMissing($first);

    $this->actingAs($this->admin)->put(route('spklu-operators.update', $operator), spkluPayload([
        'logo' => null, 'remove_logo' => '1',
    ]))->assertSessionHasNoErrors();

    expect($operator->fresh()->logo)->toBeNull();
    Storage::disk(SpkluOperator::DISK)->assertMissing($second);
});

test('validation rejects invalid input', function (Closure $overrides, string $errorField) {
    SpkluOperator::factory()->create(['brand_spklu' => 'Voltron']);

    $this->actingAs($this->admin)->post(route('spklu-operators.store'), spkluPayload($overrides()))
        ->assertSessionHasErrors($errorField);

    expect(SpkluOperator::count())->toBe(1);
})->with([
    'missing brand' => [fn () => ['brand_spklu' => ''], 'brand_spklu'],
    'duplicate brand' => [fn () => ['brand_spklu' => 'Voltron'], 'brand_spklu'],
    'missing operator' => [fn () => ['nama_operator' => ''], 'nama_operator'],
    'missing app' => [fn () => ['aplikasi' => ''], 'aplikasi'],
    'svg logo' => [fn () => ['logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')], 'logo'],
    'javascript link' => [fn () => ['link_playstore' => 'javascript:alert(1)'], 'link_playstore'],
    'http link' => [fn () => ['link_playstore' => 'http://play.google.com/store/apps/details?id=x'], 'link_playstore'],
    'foreign play host' => [fn () => ['link_playstore' => 'https://evil.example/play.google.com'], 'link_playstore'],
    'lookalike app host' => [fn () => ['link_appstore' => 'https://apps.apple.com.evil.example/app'], 'link_appstore'],
    'invalid status' => [fn () => ['status' => 'archived'], 'status'],
]);

test('delete removes the operator and its logo', function () {
    $this->actingAs($this->admin)->post(route('spklu-operators.store'), spkluPayload());
    $operator = SpkluOperator::sole();

    $this->actingAs($this->admin)->delete(route('spklu-operators.destroy', $operator))
        ->assertRedirect(route('spklu-operators.index'));

    expect(SpkluOperator::count())->toBe(0);
    Storage::disk(SpkluOperator::DISK)->assertMissing($operator->logo);
});
