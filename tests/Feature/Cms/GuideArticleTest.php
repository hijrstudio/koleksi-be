<?php

use App\Models\GuideArticle;
use App\Models\GuideCategory;
use App\Models\Member;
use App\Models\User;
use App\Support\RichTextSanitizer;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(GuideArticle::DISK, ['url' => 'http://localhost/storage']);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
    $this->category = GuideCategory::factory()->published()->create();
});

function articlePayload(array $overrides = []): array
{
    return array_merge([
        'guide_category_id' => test()->category->id,
        'title' => ['id' => 'Cara Merawat Baterai Mobil Listrik', 'en' => 'How to Care for an EV Battery'],
        'content' => ['id' => '<p>Isi <strong>panduan</strong></p>', 'en' => '<p>Guide content</p>'],
        'status' => 'draft',
        'author_type' => 'user',
    ], $overrides);
}

test('guest cannot access guide articles', function () {
    $this->get(route('guide-articles.index'))->assertRedirect(route('signin'));
});

test('index supports search and category, status & author filters', function () {
    $other = GuideCategory::factory()->create();
    GuideArticle::factory()->published()->for($this->category, 'category')
        ->create(['title' => ['id' => 'Tips Mengisi Daya', 'en' => 'Charging Tips']]);
    GuideArticle::factory()->byMember(Member::factory()->create(['nama_lengkap' => 'Budi Penulis']))->for($other, 'category')
        ->create(['title' => ['id' => 'Merawat Ban', 'en' => 'Tyre Care']]);

    $this->actingAs($this->admin)->get(route('guide-articles.index'))
        ->assertOk()->assertSee('Tips Mengisi Daya')->assertSee('Merawat Ban')->assertSee('Budi Penulis');

    $this->actingAs($this->admin)->get(route('guide-articles.index', ['search' => 'Charging']))
        ->assertOk()->assertSee('Tips Mengisi Daya')->assertDontSee('Merawat Ban');

    $this->actingAs($this->admin)->get(route('guide-articles.index', ['category' => $other->id]))
        ->assertOk()->assertSee('Merawat Ban')->assertDontSee('Tips Mengisi Daya');

    $this->actingAs($this->admin)->get(route('guide-articles.index', ['status' => 'published']))
        ->assertOk()->assertSee('Tips Mengisi Daya')->assertDontSee('Merawat Ban');

    $this->actingAs($this->admin)->get(route('guide-articles.index', ['author' => 'member']))
        ->assertOk()->assertSee('Merawat Ban')->assertDontSee('Tips Mengisi Daya');
});

test('admin can create an article authored by themselves', function () {
    $this->actingAs($this->admin)->get(route('guide-articles.create'))->assertOk();

    $this->actingAs($this->admin)->post(route('guide-articles.store'), articlePayload([
        'image' => UploadedFile::fake()->image('cover.jpg', 1200, 630),
        'status' => 'published',
    ]))->assertRedirect(route('guide-articles.index'))->assertSessionHas('success');

    $article = GuideArticle::sole();

    expect($article->author_user_id)->toBe($this->admin->id)
        ->and($article->author_member_id)->toBeNull()
        ->and($article->authorName())->toBe($this->admin->name)
        ->and($article->slug)->toBe('cara-merawat-baterai-mobil-listrik')
        ->and($article->published_at)->not->toBeNull()
        ->and($article->content['id'])->toBe('<p>Isi <strong>panduan</strong></p>');

    Storage::disk(GuideArticle::DISK)->assertExists($article->image);
});

test('admin can create an article authored by a member', function () {
    $member = Member::factory()->create();

    $this->actingAs($this->admin)->post(route('guide-articles.store'), articlePayload([
        'author_type' => 'member',
        'author_member_id' => $member->id,
    ]))->assertSessionHasNoErrors();

    $article = GuideArticle::sole();

    expect($article->author_member_id)->toBe($member->id)
        ->and($article->author_user_id)->toBeNull()
        ->and($article->authorType())->toBe(GuideArticle::AUTHOR_MEMBER)
        ->and($article->created_by)->toBe($this->admin->id); // audit tetap mencatat admin yang input
});

test('slug stays unique and does not change when title is edited', function () {
    $this->actingAs($this->admin)->post(route('guide-articles.store'), articlePayload());
    $this->actingAs($this->admin)->post(route('guide-articles.store'), articlePayload());

    [$first, $second] = GuideArticle::orderBy('id')->get()->all();
    expect($second->slug)->toBe('cara-merawat-baterai-mobil-listrik-2');

    $this->actingAs($this->admin)->put(route('guide-articles.update', $first), articlePayload([
        'title' => ['id' => 'Judul Baru', 'en' => null],
    ]))->assertSessionHasNoErrors();

    expect($first->fresh()->slug)->toBe('cara-merawat-baterai-mobil-listrik');
});

test('slug from the form is normalized and saved', function () {
    $this->actingAs($this->admin)->post(route('guide-articles.store'), articlePayload([
        'slug' => '  Tips Mengisi Daya di Rumah!! ',
        'slug_auto' => '0',
    ]))->assertSessionHasNoErrors();

    expect(GuideArticle::sole()->slug)->toBe('tips-mengisi-daya-di-rumah');
});

test('auto slug that is already taken gets a suffix instead of an error', function () {
    GuideArticle::factory()->create()->forceFill(['slug' => 'tips-mengisi-daya'])->save();

    $this->actingAs($this->admin)->post(route('guide-articles.store'), articlePayload([
        'slug' => 'tips-mengisi-daya',
        'slug_auto' => '1',
    ]))->assertSessionHasNoErrors();

    expect(GuideArticle::latest('id')->first()->slug)->toBe('tips-mengisi-daya-2');
});

test('manual slug that is already taken is rejected', function () {
    GuideArticle::factory()->create()->forceFill(['slug' => 'tips-mengisi-daya'])->save();

    $this->actingAs($this->admin)->post(route('guide-articles.store'), articlePayload([
        'slug' => 'tips-mengisi-daya',
        'slug_auto' => '0',
    ]))->assertSessionHasErrors('slug');

    expect(GuideArticle::count())->toBe(1);
});

test('article may keep its own slug and can change it on edit', function () {
    $this->actingAs($this->admin)->post(route('guide-articles.store'), articlePayload(['slug' => 'slug-awal']));
    $article = GuideArticle::sole();

    // Slug sendiri tidak dianggap bentrok (manual maupun otomatis)
    $this->actingAs($this->admin)->put(route('guide-articles.update', $article), articlePayload(['slug' => 'slug-awal', 'slug_auto' => '0']))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put(route('guide-articles.update', $article), articlePayload(['slug' => 'slug-awal', 'slug_auto' => '1']))
        ->assertSessionHasNoErrors();
    expect($article->fresh()->slug)->toBe('slug-awal');

    $this->actingAs($this->admin)->put(route('guide-articles.update', $article), articlePayload(['slug' => 'slug-baru']))
        ->assertSessionHasNoErrors();
    expect($article->fresh()->slug)->toBe('slug-baru');

    // Slug dikosongkan saat edit: slug lama dipertahankan
    $this->actingAs($this->admin)->put(route('guide-articles.update', $article), articlePayload(['slug' => '']))
        ->assertSessionHasNoErrors();
    expect($article->fresh()->slug)->toBe('slug-baru');
});

test('edit form shows the slug field', function () {
    $this->actingAs($this->admin)->post(route('guide-articles.store'), articlePayload(['slug' => 'slug-tampil']));

    $this->actingAs($this->admin)->get(route('guide-articles.edit', GuideArticle::sole()))
        ->assertOk()->assertSee('slug-tampil')->assertSee('name="slug"', false);
});

test('editing keeps the original admin author and switching author type works', function () {
    $originalAdmin = User::factory()->create();
    $article = GuideArticle::factory()->byUser($originalAdmin)->for($this->category, 'category')->create();
    $member = Member::factory()->create();

    // Admin lain mengedit tanpa mengganti tipe penulis: penulis tetap admin asli
    $this->actingAs($this->admin)->put(route('guide-articles.update', $article), articlePayload())->assertSessionHasNoErrors();
    expect($article->fresh()->author_user_id)->toBe($originalAdmin->id);

    // Ganti ke member
    $this->actingAs($this->admin)->put(route('guide-articles.update', $article), articlePayload([
        'author_type' => 'member', 'author_member_id' => $member->id,
    ]))->assertSessionHasNoErrors();
    expect($article->fresh())->author_member_id->toBe($member->id)->author_user_id->toBeNull();

    // Kembali ke admin: penulis = admin yang login
    $this->actingAs($this->admin)->put(route('guide-articles.update', $article), articlePayload())->assertSessionHasNoErrors();
    expect($article->fresh())->author_user_id->toBe($this->admin->id)->author_member_id->toBeNull();
});

test('replacing and removing the image deletes the old file', function () {
    $this->actingAs($this->admin)->post(route('guide-articles.store'), articlePayload(['image' => UploadedFile::fake()->image('a.jpg')]));
    $article = GuideArticle::sole();
    $first = $article->image;

    $this->actingAs($this->admin)->put(route('guide-articles.update', $article), articlePayload(['image' => UploadedFile::fake()->image('b.png')]));
    $second = $article->fresh()->image;

    Storage::disk(GuideArticle::DISK)->assertMissing($first);
    Storage::disk(GuideArticle::DISK)->assertExists($second);

    $this->actingAs($this->admin)->put(route('guide-articles.update', $article), articlePayload(['remove_image' => '1']));

    expect($article->fresh()->image)->toBeNull();
    Storage::disk(GuideArticle::DISK)->assertMissing($second);
});

test('content is sanitized against xss', function () {
    $this->actingAs($this->admin)->post(route('guide-articles.store'), articlePayload([
        'content' => ['id' => '<p onclick="x()">Halo<script>alert(1)</script><img src=x onerror=alert(1)></p>', 'en' => null],
    ]))->assertSessionHasNoErrors();

    expect(GuideArticle::sole()->content['id'])->toBe('<p>Halo</p>');
});

test('validation rejects invalid input', function (Closure $overrides, string $errorField) {
    $this->actingAs($this->admin)->post(route('guide-articles.store'), articlePayload($overrides()))
        ->assertSessionHasErrors($errorField);

    expect(GuideArticle::count())->toBe(0);
})->with([
    'missing category' => [fn () => ['guide_category_id' => null], 'guide_category_id'],
    'unknown category' => [fn () => ['guide_category_id' => 999], 'guide_category_id'],
    'missing title id' => [fn () => ['title' => ['id' => '', 'en' => 'T']], 'title.id'],
    'empty editor content' => [fn () => ['content' => ['id' => '<p></p>', 'en' => null]], 'content.id'],
    'invalid author type' => [fn () => ['author_type' => 'guest'], 'author_type'],
    'member author without member' => [fn () => ['author_type' => 'member'], 'author_member_id'],
    'unknown member' => [fn () => ['author_type' => 'member', 'author_member_id' => 999], 'author_member_id'],
    'soft deleted member' => [fn () => ['author_type' => 'member', 'author_member_id' => tap(Member::factory()->create())->delete()->id], 'author_member_id'],
    'svg image' => [fn () => ['image' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')], 'image'],
    'image too large' => [fn () => ['image' => UploadedFile::fake()->image('big.jpg')->size(6 * 1024)], 'image'],
]);

test('author_member_id is ignored when author is admin', function () {
    $member = Member::factory()->create();

    $this->actingAs($this->admin)->post(route('guide-articles.store'), articlePayload(['author_member_id' => $member->id]))
        ->assertSessionHasNoErrors();

    expect(GuideArticle::sole())->author_user_id->toBe($this->admin->id)->author_member_id->toBeNull();
});

test('model refuses to save an article without exactly one author', function () {
    $article = GuideArticle::factory()->make();
    $article->author_member_id = Member::factory()->create()->id; // dua-duanya terisi

    expect(fn () => $article->save())->toThrow(LogicException::class);
});

test('member options endpoint searches members and hides deleted ones', function () {
    Member::factory()->create(['nama_lengkap' => 'Budi Santoso', 'no_lambung' => 7]);
    Member::factory()->create(['nama_lengkap' => 'Budi Terhapus'])->delete();
    Member::factory()->create(['nama_lengkap' => 'Siti Aminah']);

    $this->actingAs($this->admin)->getJson(route('guide-articles.member-options', ['q' => 'Budi']))
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.label', 'Budi Santoso (#007)');
});

test('member options endpoint rejects overly long queries', function () {
    $this->actingAs($this->admin)->getJson(route('guide-articles.member-options', ['q' => str_repeat('a', 101)]))
        ->assertUnprocessable();
});

test('visible scope requires both article and category to be published', function () {
    $draftCategory = GuideCategory::factory()->create();
    GuideArticle::factory()->published()->for($this->category, 'category')->create(['title' => ['id' => 'Tampil', 'en' => null]]);
    GuideArticle::factory()->published()->for($draftCategory, 'category')->create(['title' => ['id' => 'Kategori draft', 'en' => null]]);
    GuideArticle::factory()->for($this->category, 'category')->create(['title' => ['id' => 'Artikel draft', 'en' => null]]);

    expect(GuideArticle::visible()->get()->map->translate('title', 'id')->all())->toBe(['Tampil']);
});

test('delete removes the article and its image', function () {
    $this->actingAs($this->admin)->post(route('guide-articles.store'), articlePayload(['image' => UploadedFile::fake()->image('a.jpg')]));
    $article = GuideArticle::sole();

    $this->actingAs($this->admin)->delete(route('guide-articles.destroy', $article))
        ->assertRedirect(route('guide-articles.index'));

    expect(GuideArticle::count())->toBe(0);
    Storage::disk(GuideArticle::DISK)->assertMissing($article->image);
});

test('policy follows guide-articles.* permissions for non super admin roles', function () {
    $editor = User::factory()->create();
    $article = GuideArticle::factory()->create();

    expect($editor->can('viewAny', GuideArticle::class))->toBeFalse();

    $editor->givePermissionTo('guide-articles.view', 'guide-articles.update');

    expect($editor->can('viewAny', GuideArticle::class))->toBeTrue()
        ->and($editor->can('update', $article))->toBeTrue()
        ->and($editor->can('create', GuideArticle::class))->toBeFalse()
        ->and($editor->can('delete', $article))->toBeFalse();
});

test('content image upload stores the file in the content directory and returns its url', function () {
    $response = $this->actingAs($this->admin)
        ->postJson(route('guide-articles.content-images.store'), ['image' => UploadedFile::fake()->image('foto.png', 800, 600)])
        ->assertCreated();

    $path = $response->json('path');

    expect($path)->toStartWith(GuideArticle::CONTENT_IMAGE_DIRECTORY.'/'.now()->format('Y/m').'/')
        ->and($response->json('url'))->toBe('http://localhost/storage/'.$path);

    Storage::disk(GuideArticle::DISK)->assertExists($path);
});

test('content image upload rejects invalid files and guests', function (Closure $file) {
    $this->actingAs($this->admin)
        ->postJson(route('guide-articles.content-images.store'), ['image' => $file()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');
})->with([
    'svg' => [fn () => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')],
    'php disguised as jpg' => [fn () => UploadedFile::fake()->create('shell.jpg', 5, 'text/x-php')],
    'too large' => [fn () => UploadedFile::fake()->image('big.jpg')->size(6 * 1024)],
]);

test('guest cannot upload content images', function () {
    $this->postJson(route('guide-articles.content-images.store'), ['image' => UploadedFile::fake()->image('a.jpg')])
        ->assertUnauthorized();
});

test('content keeps uploaded images but strips external, data and script images', function () {
    $own = GuideArticle::contentImageBaseUrl().'2026/10/abc.jpg';

    $this->actingAs($this->admin)->post(route('guide-articles.store'), articlePayload([
        'content' => ['id' => '<p>Teks</p>'
            ."<img src=\"{$own}\" alt=\"Baterai\" onerror=\"alert(1)\">"
            .'<img src="https://evil.test/track.gif">'
            .'<img src="data:image/png;base64,AAAA">'
            .'<img src="javascript:alert(1)">'
            .'<img src="'.GuideArticle::contentImageBaseUrl().'../../.env">', 'en' => null],
    ]))->assertSessionHasNoErrors();

    expect(GuideArticle::sole()->content['id'])->toBe("<p>Teks</p><img src=\"{$own}\" alt=\"Baterai\" />");
});

test('content with only an image is not considered empty', function () {
    $own = GuideArticle::contentImageBaseUrl().'2026/10/abc.jpg';

    $this->actingAs($this->admin)->post(route('guide-articles.store'), articlePayload([
        'content' => ['id' => "<img src=\"{$own}\">", 'en' => null],
    ]))->assertSessionHasNoErrors();
});

test('rich text outside articles still does not allow images', function () {
    $own = GuideArticle::contentImageBaseUrl().'2026/10/abc.jpg';

    expect(app(RichTextSanitizer::class)->clean("<p>FAQ</p><img src=\"{$own}\">"))->toBe('<p>FAQ</p>');
});

test('prune command deletes only old unused content images', function () {
    $disk = Storage::disk(GuideArticle::DISK);
    $dir = GuideArticle::CONTENT_IMAGE_DIRECTORY.'/2026/10';

    foreach (['used.jpg', 'orphan.jpg', 'fresh.jpg'] as $file) {
        $disk->put("$dir/$file", 'img');
    }
    touch($disk->path("$dir/used.jpg"), now()->subDays(3)->getTimestamp());
    touch($disk->path("$dir/orphan.jpg"), now()->subDays(3)->getTimestamp());

    GuideArticle::factory()->create([
        'content' => ['id' => '<img src="'.GuideArticle::contentImageBaseUrl().'2026/10/used.jpg">', 'en' => null],
    ]);

    $this->artisan('content:prune-images', ['--dry-run' => true])->assertSuccessful();
    $disk->assertExists("$dir/orphan.jpg");

    $this->artisan('content:prune-images')->assertSuccessful();

    $disk->assertExists("$dir/used.jpg");   // dipakai artikel
    $disk->assertExists("$dir/fresh.jpg");  // baru diupload, mungkin form belum disimpan
    $disk->assertMissing("$dir/orphan.jpg");
});
