<?php

use App\Models\GuideRegulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
    Storage::fake(GuideRegulation::PDF_DISK);
});

test('regulation endpoints require frontend token', function () {
    $regulation = GuideRegulation::factory()->published()->create();

    $this->getJson(route('api.v1.guides.regulations.index'))->assertUnauthorized();
    $this->getJson(route('api.v1.guides.regulations.file', $regulation->id))->assertUnauthorized();
});

test('index paginates 5 per page, newest first, published only', function () {
    foreach (range(1, 6) as $i) {
        GuideRegulation::factory()->published()->create(['created_at' => now()->subDays($i), 'title' => "Dokumen $i"]);
    }
    GuideRegulation::factory()->create(['created_at' => now()]); // draft

    $this->withToken('test-token')->getJson(route('api.v1.guides.regulations.index'))
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('data.0.title', 'Dokumen 1')
        ->assertJsonPath('meta.total', 6)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonStructure(['data' => [['id', 'title', 'published_at']]])
        ->assertJsonMissingPath('data.0.file_pdf'); // path file tidak dibocorkan

    $this->withToken('test-token')->getJson(route('api.v1.guides.regulations.index', ['page' => 2]))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Dokumen 6');
});

test('index searches title case-insensitively', function () {
    $match = GuideRegulation::factory()->published()->create(['title' => 'PERGUB NO. 41 TAHUN 2021 (NJKB)']);
    GuideRegulation::factory()->published()->create(['title' => 'PERDA NO.1 TAHUN 2024 PDRB']);

    $get = fn (array $q) => $this->withToken('test-token')->getJson(route('api.v1.guides.regulations.index', $q));

    $get(['search' => 'njkb'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
    $get(['search' => '100%'])->assertJsonCount(0, 'data');
    $get(['per_page' => 100])->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

test('index query count stays constant (no N+1)', function () {
    GuideRegulation::factory()->count(5)->published()->create();

    DB::enableQueryLog();
    $this->withToken('test-token')->getJson(route('api.v1.guides.regulations.index'))->assertOk();

    // count + data
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(2);
});

test('file streams the pdf inline for published regulations only', function () {
    $published = GuideRegulation::factory()->published()->create(['title' => 'PERPRES NO. 55 TAHUN 2019']);
    $draft = GuideRegulation::factory()->create();
    $missing = GuideRegulation::factory()->published()->create();
    Storage::disk(GuideRegulation::PDF_DISK)->put($published->file_pdf, '%PDF-1.4 test');
    Storage::disk(GuideRegulation::PDF_DISK)->put($draft->file_pdf, '%PDF-1.4 test');

    $response = $this->withToken('test-token')->get(route('api.v1.guides.regulations.file', $published->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Content-Disposition'))->toContain('inline')->toContain('perpres-no-55-tahun-2019.pdf');

    $this->withToken('test-token')->get(route('api.v1.guides.regulations.file', $draft->id))->assertNotFound();
    $this->withToken('test-token')->get(route('api.v1.guides.regulations.file', $missing->id))->assertNotFound();
    $this->withToken('test-token')->get(route('api.v1.guides.regulations.file', 999))->assertNotFound();
});
