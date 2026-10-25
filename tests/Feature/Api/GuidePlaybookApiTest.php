<?php

use App\Models\GuidePlaybook;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
    Storage::fake(GuidePlaybook::COVER_DISK);
    Storage::fake(GuidePlaybook::PDF_DISK);

    $this->published = GuidePlaybook::factory()->published()->create(['title' => ['id' => 'Charging 101', 'en' => null]]);
    Storage::disk(GuidePlaybook::PDF_DISK)->put($this->published->file_pdf, '%PDF-1.4 isi playbook');
    $this->draft = GuidePlaybook::factory()->create(['title' => ['id' => 'Draft Rahasia', 'en' => 'Secret Draft']]);
    Storage::disk(GuidePlaybook::PDF_DISK)->put($this->draft->file_pdf, '%PDF-1.4 draft');
});

test('list requires the frontend token and shows only published playbooks without PDF paths', function () {
    $this->getJson('/api/v1/guides/playbooks')->assertUnauthorized();

    $res = $this->withToken('test-token')->getJson('/api/v1/guides/playbooks')->assertOk();

    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.title'))->toBe(['id' => 'Charging 101', 'en' => 'Charging 101'])
        ->and($res->json('data.0.cover'))->toEndWith($this->published->cover_image)
        ->and(json_encode($res->json()))->not->toContain('.pdf')
        ->and($res->json('meta.total'))->toBe(1);
});

test('list searches title in ID & EN and paginates', function () {
    GuidePlaybook::factory()->published()->count(10)->create();

    $this->withToken('test-token')->getJson('/api/v1/guides/playbooks?search=charging')
        ->assertOk()->assertJsonCount(1, 'data');

    $this->withToken('test-token')->getJson('/api/v1/guides/playbooks')
        ->assertJsonCount(9, 'data')->assertJsonPath('meta.last_page', 2);
});

test('anyone with the frontend token can read a published PDF inline, never a draft', function () {
    $this->getJson("/api/v1/guides/playbooks/{$this->published->id}/file")->assertUnauthorized();

    $res = $this->withToken('test-token')->get("/api/v1/guides/playbooks/{$this->published->id}/file")
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($res->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($res->streamedContent())->toBe('%PDF-1.4 isi playbook');

    $this->withToken('test-token')->get("/api/v1/guides/playbooks/{$this->draft->id}/file")->assertNotFound();
});

test('only a logged-in member can download, as an attachment', function () {
    $url = "/api/v1/member/playbooks/{$this->published->id}/download";

    $this->getJson($url)->assertUnauthorized();
    $this->withToken('test-token')->getJson($url)->assertUnauthorized(); // token FE bukan token member

    $member = Member::factory()->create();
    $token = $member->createToken('web', ['member'])->plainTextToken;

    $res = $this->withToken($token)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($res->headers->get('Content-Disposition'))->toStartWith('attachment')->toContain('charging-101.pdf');

    $this->withToken($token)->get("/api/v1/member/playbooks/{$this->draft->id}/download")->assertNotFound();
});
