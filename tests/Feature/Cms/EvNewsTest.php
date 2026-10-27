<?php

use App\Models\News;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(News::DISK, ['url' => 'http://localhost/storage']);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

/** Berita EV: tautan ke berita di media lain (tanpa konten & penulis KOLEKSI). */
function evNewsPayload(array $overrides = []): array
{
    return array_merge([
        'category' => 'ev_news',
        'title' => ['id' => 'Penjualan Mobil Listrik Naik', 'en' => 'EV Sales Rise'],
        'summary' => ['id' => 'Penjualan mobil listrik naik 40 persen.', 'en' => ''],
        'source_url' => 'kompas.com/otomotif/penjualan-ev',
        'source_name' => 'Kompas.com',
        'source_author' => 'Andi Wartawan',
        'source_published_on' => '2026-10-20',
        'status' => 'published',
        // Ikut terkirim walau field-nya disembunyikan di form → harus diabaikan
        'content' => ['id' => '<p>tidak dipakai</p>', 'en' => ''],
        'author_type' => 'member',
    ], $overrides);
}

test('ev news is saved as a link to another media without content', function () {
    $this->actingAs($this->admin)->post(route('news.store'), evNewsPayload([
        'image' => UploadedFile::fake()->image('cover.jpg', 1200, 630),
    ]))->assertRedirect(route('news.index'))->assertSessionHas('success');

    $news = News::sole();

    expect($news->isExternal())->toBeTrue()
        ->and($news->content)->toBeNull()
        ->and($news->summary)->toBe(['id' => 'Penjualan mobil listrik naik 40 persen.', 'en' => null])
        ->and($news->source_url)->toBe('https://kompas.com/otomotif/penjualan-ev') // https:// dilengkapi
        ->and($news->source_name)->toBe('Kompas.com')
        ->and($news->source_author)->toBe('Andi Wartawan')
        ->and($news->source_published_on->toDateString())->toBe('2026-10-20')
        ->and($news->author_user_id)->toBe($this->admin->id) // penulis internal = admin yang login, bukan member
        ->and($news->author_member_id)->toBeNull()
        ->and($news->image)->not->toBeNull();

    $this->actingAs($this->admin)->get(route('news.index'))->assertOk()->assertSee('Andi Wartawan')->assertSee('Kompas.com');
    $this->actingAs($this->admin)->get(route('news.edit', $news))->assertOk()
        ->assertSee('https://kompas.com/otomotif/penjualan-ev')->assertSee('2026-10-20');
});

test('ev news validation requires the source fields and only http/https links', function (array $overrides, string $errorField) {
    $this->actingAs($this->admin)->post(route('news.store'), evNewsPayload($overrides))->assertSessionHasErrors($errorField);

    expect(News::count())->toBe(0);
})->with([
    'ringkasan wajib' => [['summary' => ['id' => '', 'en' => '']], 'summary.id'],
    'link wajib' => [['source_url' => ''], 'source_url'],
    'link javascript:' => [['source_url' => 'javascript:alert(1)'], 'source_url'],
    'media wajib' => [['source_name' => ''], 'source_name'],
    'tanggal wajib' => [['source_published_on' => ''], 'source_published_on'],
    'tanggal di masa depan' => [['source_published_on' => '2999-01-01'], 'source_published_on'],
]);

test('switching an ev news to another category requires content and clears the source fields', function () {
    $this->actingAs($this->admin)->post(route('news.store'), evNewsPayload());
    $news = News::sole();

    $this->actingAs($this->admin)->put(route('news.update', $news), evNewsPayload([
        'category' => 'news_release',
        'content' => ['id' => '', 'en' => ''],
        'author_type' => 'user',
    ]))->assertSessionHasErrors('content.id');

    $this->actingAs($this->admin)->put(route('news.update', $news), evNewsPayload([
        'category' => 'news_release',
        'content' => ['id' => '<p>Isi rilis</p>', 'en' => ''],
        'author_type' => 'user',
    ]))->assertRedirect(route('news.index'));

    $news->refresh();
    expect($news->isExternal())->toBeFalse()
        ->and($news->content['id'])->toBe('<p>Isi rilis</p>')
        ->and($news->summary)->toBeNull()
        ->and($news->source_url)->toBeNull()
        ->and($news->source_name)->toBeNull()
        ->and($news->source_author)->toBeNull()
        ->and($news->source_published_on)->toBeNull();
});
