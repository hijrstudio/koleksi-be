<?php

use App\Models\Member;
use App\Models\Setting;
use App\Models\User;
use App\Services\MembershipFees;
use App\Services\MembershipService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['membership.annual_fee' => 300000, 'membership.registration_fee' => 0, 'membership.arrears_cutoff_year' => null]);
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Bendahara']);
    $this->admin->assignRole('super-admin');
    $this->fees = app(MembershipFees::class);
});

test('until the committee sets prices, .env is used and the registration fee equals the annual fee', function () {
    expect($this->fees->annual())->toBe(300000)
        ->and($this->fees->registration())->toBe(300000);

    config(['membership.registration_fee' => 450000]);
    expect($this->fees->registration())->toBe(450000);
});

test('prices saved in the CMS win over .env and apply per member type', function () {
    $this->fees->update(500000, 350000, $this->admin);

    $registrant = Member::factory()->create(['no_lambung' => null, 'registration_status' => Member::REG_AWAITING_PAYMENT]);
    $member = Member::factory()->create(['no_lambung' => 9]);

    expect($this->fees->registration())->toBe(500000)
        ->and($this->fees->annual())->toBe(350000)
        ->and($this->fees->forMember($registrant))->toBe(500000)
        ->and($this->fees->forMember($member))->toBe(350000)
        ->and(Setting::query()->find(Setting::ANNUAL_FEE)->updated_by)->toBe($this->admin->id);
});

test('issued invoices keep their amount when the price changes', function () {
    $membership = app(MembershipService::class);
    $member = Member::factory()->create(['no_lambung' => 9, 'tgl_bergabung' => today()]);
    $due = $membership->invoiceFor($member, $membership->currentTerm($member));

    $this->fees->update(500000, 400000, $this->admin);

    expect($due->fresh()->amount)->toBe(300000);
});

test('admin sets the prices in the member list; the change is shown with who made it', function () {
    $this->actingAs($this->admin)->get(route('members.index'))->assertOk()
        ->assertSee('Rp300.000');

    $this->actingAs($this->admin)->put(route('members.fees.update'), ['registration_fee' => 500000, 'annual_fee' => 300000])
        ->assertRedirect(route('members.index'));

    $this->actingAs($this->admin)->get(route('members.index'))
        ->assertSee('Pendaftaran: <span class="font-semibold">Rp500.000</span>', false)
        ->assertSee('oleh Bendahara');
});

test('prices are validated and need the members.update permission', function () {
    foreach ([['registration_fee' => 0, 'annual_fee' => 300000], ['registration_fee' => 'abc', 'annual_fee' => 300000],
        ['registration_fee' => 500000, 'annual_fee' => MembershipFees::MAX + 1], ['registration_fee' => 500000]] as $payload) {
        $this->actingAs($this->admin)->put(route('members.fees.update'), $payload)->assertSessionHasErrors();
    }

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('members.view');
    expect($viewer->can('updateSettings', Member::class))->toBeFalse();
    $this->actingAs($viewer)->put(route('members.fees.update'), ['registration_fee' => 1, 'annual_fee' => 1])->assertForbidden();

    expect(Setting::get(Setting::REGISTRATION_FEE))->toBeNull();
});
