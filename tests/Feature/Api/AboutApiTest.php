<?php

use App\Models\AboutUs;
use App\Models\Faq;
use App\Models\ManagementMember;
use App\Models\ManagementPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.frontend.api_token' => 'test-token']);
});

test('requires frontend token', function () {
    $this->getJson(route('api.v1.about'))->assertUnauthorized();
});

test('works when about us has never been filled', function () {
    $this->withToken('test-token')->getJson(route('api.v1.about'))
        ->assertOk()
        ->assertJsonPath('data.summary.id', null)
        ->assertJsonPath('data.documents.aturan_ad_art', null)
        ->assertJsonPath('data.management', null)
        ->assertJsonPath('data.faqs', []);
});

test('returns about content with english falling back to indonesian', function () {
    $about = new AboutUs([
        'summary' => ['id' => '<p>Ringkasan</p>', 'en' => '<p>Summary</p>'],
        'visi' => ['id' => 'Visi kami', 'en' => null],
        'aturan_ad_art' => 'about-us/ad-art.pdf',
    ]);
    $about->id = AboutUs::SINGLETON_ID;
    $about->save();

    $this->withToken('test-token')->getJson(route('api.v1.about'))
        ->assertOk()
        ->assertJsonPath('data.summary.en', '<p>Summary</p>')
        ->assertJsonPath('data.visi.en', 'Visi kami')
        ->assertJsonPath('data.documents.sponsor', null)
        ->assertJson(fn ($json) => $json->where('data.documents.aturan_ad_art', fn ($url) => str_ends_with($url, 'about-us/ad-art.pdf'))->etc());
});

test('returns latest published period with members split by group', function () {
    ManagementPeriod::factory()->published()->create(['start_year' => 2019, 'end_year' => 2024]);
    ManagementPeriod::factory()->create(['start_year' => 2029, 'end_year' => 2034]); // draft
    $period = ManagementPeriod::factory()->published()->create(['start_year' => 2024, 'end_year' => 2029]);

    $member = fn (array $attrs) => ManagementMember::factory()->for($period, 'period')->create($attrs);
    $member(['group' => 'bidang', 'name' => 'Bidang A', 'sort_order' => 1]);
    $member(['group' => 'penasihat', 'name' => 'Wakil', 'sort_order' => 2]);
    $member(['group' => 'penasihat', 'name' => 'Ketua', 'sort_order' => 1]);

    $this->withToken('test-token')->getJson(route('api.v1.about'))
        ->assertOk()
        ->assertJsonPath('data.management.period.label', '2024–2029')
        ->assertJsonPath('data.management.penasihat.*.name', ['Ketua', 'Wakil'])
        ->assertJsonPath('data.management.bidang.*.name', ['Bidang A']);
});

test('returns published faqs in sort order', function () {
    $second = Faq::factory()->published()->create(['sort_order' => 2]);
    $first = Faq::factory()->published()->create(['sort_order' => 1]);
    Faq::factory()->create(); // draft

    $this->withToken('test-token')->getJson(route('api.v1.about'))
        ->assertJsonPath('data.faqs.*.id', [$first->id, $second->id]);
});

test('query count stays constant (no N+1)', function () {
    $period = ManagementPeriod::factory()->published()->create();
    ManagementMember::factory()->count(10)->for($period, 'period')->create();
    Faq::factory()->count(10)->published()->create();

    DB::enableQueryLog();
    $this->withToken('test-token')->getJson(route('api.v1.about'))->assertOk();

    expect(DB::getQueryLog())->toHaveCount(4);
});
