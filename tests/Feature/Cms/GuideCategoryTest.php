<?php

use App\Enums\ContentStatus;
use App\Models\GuideArticle;
use App\Models\GuideCategory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

test('guest cannot access guide categories', function () {
    $this->get(route('guide-categories.index'))->assertRedirect(route('signin'));
});

test('index lists categories with article count and supports search & status filter', function () {
    $battery = GuideCategory::factory()->published()->create(['name' => ['id' => 'Perawatan Baterai', 'en' => 'Battery Care']]);
    GuideArticle::factory()->count(2)->for($battery, 'category')->create();
    GuideCategory::factory()->create(['name' => ['id' => 'Pengisian Daya', 'en' => 'Charging']]);

    $this->actingAs($this->admin)->get(route('guide-categories.index'))
        ->assertOk()->assertSee('Perawatan Baterai')->assertSee('Pengisian Daya')->assertSee('2 artikel');

    $this->actingAs($this->admin)->get(route('guide-categories.index', ['search' => 'Charging']))
        ->assertOk()->assertSee('Pengisian Daya')->assertDontSee('Perawatan Baterai');

    $this->actingAs($this->admin)->get(route('guide-categories.index', ['status' => 'published']))
        ->assertOk()->assertSee('Perawatan Baterai')->assertDontSee('Pengisian Daya');
});

test('super admin can create, update and delete a category', function () {
    $this->actingAs($this->admin)->get(route('guide-categories.create'))->assertOk();

    $this->actingAs($this->admin)->post(route('guide-categories.store'), [
        'name' => ['id' => 'Perawatan Baterai', 'en' => ''],
        'status' => 'draft',
    ])->assertRedirect(route('guide-categories.index'));

    $category = GuideCategory::sole();
    expect($category->name)->toBe(['id' => 'Perawatan Baterai', 'en' => null])
        ->and($category->nameFor('en'))->toBe('Perawatan Baterai')
        ->and($category->created_by)->toBe($this->admin->id);

    $this->actingAs($this->admin)->get(route('guide-categories.edit', $category))->assertOk();

    $this->actingAs($this->admin)->put(route('guide-categories.update', $category), [
        'name' => ['id' => 'Perawatan Baterai', 'en' => 'Battery Care'],
        'status' => 'published',
    ])->assertRedirect(route('guide-categories.index'))->assertSessionHasNoErrors();

    expect($category->fresh())
        ->name->toBe(['id' => 'Perawatan Baterai', 'en' => 'Battery Care'])
        ->status->toBe(ContentStatus::Published);

    $this->actingAs($this->admin)->delete(route('guide-categories.destroy', $category))
        ->assertRedirect(route('guide-categories.index'));

    expect(GuideCategory::count())->toBe(0);
});

test('category that still has articles cannot be deleted', function () {
    $category = GuideCategory::factory()->create();
    GuideArticle::factory()->for($category, 'category')->create();

    $this->actingAs($this->admin)->from(route('guide-categories.index'))
        ->delete(route('guide-categories.destroy', $category))
        ->assertRedirect(route('guide-categories.index'))
        ->assertSessionHas('error');

    expect($category->fresh())->not->toBeNull();
});

test('validation rejects invalid input', function (array $payload, string $errorField) {
    GuideCategory::factory()->create(['name' => ['id' => 'Sudah Ada', 'en' => null]]);

    $this->actingAs($this->admin)->post(route('guide-categories.store'), array_merge([
        'name' => ['id' => 'Kategori', 'en' => 'Category'],
        'status' => 'draft',
    ], $payload))->assertSessionHasErrors($errorField);
})->with([
    'missing name id' => [['name' => ['id' => '', 'en' => 'X']], 'name.id'],
    'duplicate name' => [['name' => ['id' => 'Sudah Ada', 'en' => null]], 'name.id'],
    'name too long' => [['name' => ['id' => str_repeat('a', 151), 'en' => null]], 'name.id'],
    'unknown locale key' => [['name' => ['id' => 'X', 'fr' => 'Y']], 'name'],
    'invalid status' => [['status' => 'archived'], 'status'],
]);

test('updating a category may keep its own name', function () {
    $category = GuideCategory::factory()->create(['name' => ['id' => 'Sama', 'en' => null]]);

    $this->actingAs($this->admin)->put(route('guide-categories.update', $category), [
        'name' => ['id' => 'Sama', 'en' => 'Same'],
        'status' => 'draft',
    ])->assertSessionHasNoErrors();
});

test('policy follows guide-categories.* permissions for non super admin roles', function () {
    $editor = User::factory()->create();
    $category = GuideCategory::factory()->create();

    expect($editor->can('viewAny', GuideCategory::class))->toBeFalse();

    $editor->givePermissionTo('guide-categories.view', 'guide-categories.update');

    expect($editor->can('viewAny', GuideCategory::class))->toBeTrue()
        ->and($editor->can('update', $category))->toBeTrue()
        ->and($editor->can('create', GuideCategory::class))->toBeFalse()
        ->and($editor->can('delete', $category))->toBeFalse();
});
