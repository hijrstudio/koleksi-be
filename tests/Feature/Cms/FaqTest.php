<?php

use App\Enums\ContentStatus;
use App\Models\Faq;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

function faqPayload(array $overrides = []): array
{
    return array_merge([
        'question' => ['id' => 'Apa kepanjangan dari KOLEKSI?', 'en' => 'What does KOLEKSI stand for?'],
        'answer' => ['id' => '<p>Komunitas Mobil Elektrik Indonesia.</p>', 'en' => '<p>Indonesian Electric Car Community.</p>'],
        'sort_order' => 1,
        'status' => 'published',
    ], $overrides);
}

test('guest cannot access faqs', function () {
    $this->get(route('faqs.index'))->assertRedirect(route('signin'));
});

test('index lists faqs by sort order and supports search & status filter', function () {
    Faq::factory()->published()->create(['sort_order' => 2, 'question' => ['id' => 'Berapa biaya pendaftaran?', 'en' => 'How much is the fee?']]);
    Faq::factory()->create(['sort_order' => 1, 'question' => ['id' => 'Siapa yang bisa bergabung?', 'en' => 'Who can join?']]);

    $this->actingAs($this->admin)->get(route('faqs.index'))
        ->assertOk()->assertSeeInOrder(['Siapa yang bisa bergabung?', 'Berapa biaya pendaftaran?']);

    $this->actingAs($this->admin)->get(route('faqs.index', ['search' => 'fee']))
        ->assertOk()->assertSee('Berapa biaya pendaftaran?')->assertDontSee('Siapa yang bisa bergabung?');

    $this->actingAs($this->admin)->get(route('faqs.index', ['status' => 'draft']))
        ->assertOk()->assertSee('Siapa yang bisa bergabung?')->assertDontSee('Berapa biaya pendaftaran?');
});

test('create form is displayed', function () {
    $this->actingAs($this->admin)->get(route('faqs.create'))->assertOk();
});

test('super admin can create a faq', function () {
    $this->actingAs($this->admin)->post(route('faqs.store'), faqPayload())
        ->assertRedirect(route('faqs.index'))
        ->assertSessionHas('success');

    $faq = Faq::sole();

    expect($faq->question)->toBe(['id' => 'Apa kepanjangan dari KOLEKSI?', 'en' => 'What does KOLEKSI stand for?'])
        ->and($faq->answer['id'])->toBe('<p>Komunitas Mobil Elektrik Indonesia.</p>')
        ->and($faq->status)->toBe(ContentStatus::Published)
        ->and($faq->created_by)->toBe($this->admin->id);
});

test('empty english falls back to indonesian and empty sort order goes last', function () {
    Faq::factory()->create(['sort_order' => 7]);

    $this->actingAs($this->admin)->post(route('faqs.store'), faqPayload([
        'question' => ['id' => 'Pertanyaan saja', 'en' => ''],
        'answer' => ['id' => '<p>Jawaban</p>', 'en' => '<p></p>'],
        'sort_order' => '',
    ]))->assertSessionHasNoErrors();

    $faq = Faq::latest('id')->first();

    expect($faq->sort_order)->toBe(8)
        ->and($faq->question['en'])->toBeNull()
        ->and($faq->answer['en'])->toBeNull()
        ->and($faq->translate('question', 'en'))->toBe('Pertanyaan saja');
});

test('answer is sanitized against xss', function () {
    $this->actingAs($this->admin)->post(route('faqs.store'), faqPayload([
        'answer' => ['id' => '<p onclick="alert(1)">Hubungi <a href="javascript:alert(1)">x</a><script>alert(1)</script></p>', 'en' => null],
    ]))->assertSessionHasNoErrors();

    expect(Faq::sole()->answer['id'])->not->toContain('script')
        ->not->toContain('onclick')
        ->not->toContain('javascript:');
});

test('validation rejects invalid input', function (array $overrides, string $errorField) {
    $this->actingAs($this->admin)->post(route('faqs.store'), faqPayload($overrides))
        ->assertSessionHasErrors($errorField);

    expect(Faq::count())->toBe(0);
})->with([
    'missing question id' => [['question' => ['id' => '', 'en' => 'Q']], 'question.id'],
    'question too long' => [['question' => ['id' => str_repeat('a', 256), 'en' => null]], 'question.id'],
    'empty editor answer' => [['answer' => ['id' => '<p></p>', 'en' => null]], 'answer.id'],
    'script-only answer' => [['answer' => ['id' => '<script>alert(1)</script>', 'en' => null]], 'answer.id'],
    'unknown locale key' => [['question' => ['id' => 'Q', 'fr' => 'Q']], 'question'],
    'negative sort order' => [['sort_order' => -1], 'sort_order'],
    'invalid status' => [['status' => 'archived'], 'status'],
]);

test('super admin can update a faq', function () {
    $faq = Faq::factory()->create();

    $this->actingAs($this->admin)->get(route('faqs.edit', $faq))->assertOk();

    $this->actingAs($this->admin)->put(route('faqs.update', $faq), faqPayload(['sort_order' => 5]))
        ->assertRedirect(route('faqs.index'));

    expect($faq->fresh())
        ->question->toBe(['id' => 'Apa kepanjangan dari KOLEKSI?', 'en' => 'What does KOLEKSI stand for?'])
        ->sort_order->toBe(5)
        ->updated_by->toBe($this->admin->id);
});

test('super admin can delete a faq', function () {
    $faq = Faq::factory()->create();

    $this->actingAs($this->admin)->delete(route('faqs.destroy', $faq))
        ->assertRedirect(route('faqs.index'));

    expect(Faq::count())->toBe(0);
});

test('policy follows faqs.* permissions for non super admin roles', function () {
    $editor = User::factory()->create();
    $faq = Faq::factory()->create();

    expect($editor->can('viewAny', Faq::class))->toBeFalse();

    $editor->givePermissionTo('faqs.view', 'faqs.update');

    expect($editor->can('viewAny', Faq::class))->toBeTrue()
        ->and($editor->can('update', $faq))->toBeTrue()
        ->and($editor->can('create', Faq::class))->toBeFalse()
        ->and($editor->can('delete', $faq))->toBeFalse();
});
