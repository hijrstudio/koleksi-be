<?php

use App\Models\Member;
use App\Models\Setting;
use App\Models\User;
use App\Services\MembershipService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['membership.annual_fee' => 300000, 'membership.arrears_cutoff_year' => null]);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
    $this->member = Member::factory()->create(['no_lambung' => 42, 'nama_lengkap' => 'Budi Santoso', 'tgl_bergabung' => today()]);
});

test('admin changes the number: duplicates (also of deleted members) are refused, invoices follow', function () {
    $membership = app(MembershipService::class);
    $due = $membership->invoiceFor($this->member, $membership->currentTerm($this->member));
    Member::factory()->create(['no_lambung' => 50]);
    Member::factory()->create(['no_lambung' => 51])->delete();

    $this->actingAs($this->admin)->get(route('members.edit', $this->member))->assertOk()->assertSee('#042');

    foreach ([50, 51] as $taken) {
        $this->actingAs($this->admin)->put(route('members.update', $this->member), ['no_lambung' => $taken])
            ->assertSessionHasErrors('no_lambung');
    }
    $this->actingAs($this->admin)->put(route('members.update', $this->member), ['no_lambung' => 0])
        ->assertSessionHasErrors('no_lambung');

    $this->actingAs($this->admin)->put(route('members.update', $this->member), ['no_lambung' => 370])
        ->assertRedirect(route('members.show', $this->member));

    expect($this->member->fresh()->no_lambung)->toBe(370)
        ->and($due->fresh()->invoice_number)->toEndWith('-0370')
        ->and($this->member->statusLogs()->latest('id')->first()->reason)->toBe('No. lambung diubah dari #042 menjadi #370');
});

test('admin soft deletes a member: hidden, logged out, history and number kept', function () {
    $token = $this->member->createToken('member', ['member'])->plainTextToken;

    $this->actingAs($this->admin)->delete(route('members.destroy', $this->member))
        ->assertRedirect(route('members.index'));

    expect(Member::find($this->member->id))->toBeNull()
        ->and(Member::withTrashed()->find($this->member->id)->no_lambung)->toBe(42)
        ->and($this->member->tokens()->count())->toBe(0);

    $this->withToken($token)->getJson('/api/v1/member/me')->assertUnauthorized();
    $this->actingAs($this->admin)->get(route('members.show', $this->member))->assertNotFound();
    $this->actingAs($this->admin)->get(route('members.index'))->assertViewHas('summary', fn (array $s) => $s['total'] === 0);
});

test('the list offers edit and delete only to admins with the permission', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('members.view');
    $query = ['draw' => 1, 'start' => 0, 'length' => 10];

    $this->actingAs($this->admin)->getJson(route('members.data', $query))
        ->assertJsonPath('data.0.edit_url', route('members.edit', $this->member))
        ->assertJsonPath('data.0.delete_url', route('members.destroy', $this->member));

    $this->actingAs($viewer)->getJson(route('members.data', $query))
        ->assertJsonPath('data.0.edit_url', null)
        ->assertJsonPath('data.0.delete_url', null);

    $this->actingAs($viewer)->get(route('members.edit', $this->member))->assertForbidden();
    $this->actingAs($viewer)->put(route('members.update', $this->member), ['no_lambung' => 99])->assertForbidden();
    $this->actingAs($viewer)->delete(route('members.destroy', $this->member))->assertForbidden();
    $this->actingAs($viewer)->put(route('members.no-lambung-start.update'), ['no_lambung_start' => 309])->assertForbidden();

    expect($this->member->fresh()->no_lambung)->toBe(42);
});

test('admin sets the start number for new members and sees the next free number', function () {
    foreach ([309, 310] as $number) {
        Member::factory()->create(['no_lambung' => $number]);
    }

    $this->actingAs($this->admin)->get(route('members.index'))->assertViewHas('nextNoLambung', 311); // otomatis: tertinggi + 1

    $this->actingAs($this->admin)->put(route('members.no-lambung-start.update'), ['no_lambung_start' => 300])
        ->assertRedirect(route('members.index'));
    expect(Setting::get(Setting::NO_LAMBUNG_START))->toBe('300');
    $this->actingAs($this->admin)->get(route('members.index'))->assertViewHas('nextNoLambung', 300);

    $this->actingAs($this->admin)->put(route('members.no-lambung-start.update'), ['no_lambung_start' => 309]);
    $this->actingAs($this->admin)->get(route('members.index'))->assertViewHas('nextNoLambung', 311); // 309 & 310 terpakai

    $this->actingAs($this->admin)->put(route('members.no-lambung-start.update'), ['no_lambung_start' => '']);
    expect(Setting::get(Setting::NO_LAMBUNG_START))->toBeNull();
});
