<?php

use App\Models\Member;
use App\Models\MemberDue;
use App\Models\User;
use App\Services\MembershipService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create(['name' => 'Admin Satu']);
    $this->admin->assignRole('super-admin');
});

test('guest cannot open the member list', function () {
    $this->get(route('members.index'))->assertRedirect(route('signin'));
});

/** Query DataTables server-side + filter tambahan */
function dtQuery(array $overrides = []): array
{
    return array_replace_recursive([
        'draw' => 1,
        'start' => 0,
        'length' => 25,
        'search' => ['value' => ''],
        'order' => [['column' => 0, 'dir' => 'asc']],
        'status' => '',
        'contact' => '',
    ], $overrides);
}

test('member list page shows summary cards and datatable shell', function () {
    Member::factory()->active()->create();
    Member::factory()->create(['email' => null, 'no_hp' => null]);

    $this->actingAs($this->admin)->get(route('members.index'))
        ->assertOk()
        ->assertSee('id="membersTable"', false)
        ->assertSee(route('members.data'))
        ->assertViewHas('summary', ['total' => 2, 'active' => 1, 'inactive' => 1, 'no_contact' => 1, 'due_soon' => 0, 'grace' => 0]);
});

test('datatables endpoint returns paged json with search, filters and sorting', function () {
    Member::factory()->active()->create(['no_lambung' => 6, 'nama_lengkap' => 'Lihua Soedarmo', 'nama_panggilan' => 'Aweng']);
    Member::factory()->create(['no_lambung' => 7, 'nama_lengkap' => 'Budi Santoso']);
    Member::factory()->create(['no_lambung' => 8, 'nama_lengkap' => 'Tanpa Kontak', 'email' => null, 'no_hp' => null]);

    $names = fn (array $query) => $this->actingAs($this->admin)
        ->getJson(route('members.data', dtQuery($query)))
        ->assertOk()
        ->json('data.*.nama_lengkap');

    $this->actingAs($this->admin)->getJson(route('members.data', dtQuery(['draw' => 3])))
        ->assertOk()
        ->assertJsonPath('draw', 3)
        ->assertJsonPath('recordsTotal', 3)
        ->assertJsonPath('recordsFiltered', 3)
        ->assertJsonPath('data.0.no_lambung', '#006')
        ->assertJsonPath('data.0.nama_panggilan', 'Aweng')
        ->assertJsonPath('data.0.status', 'active');

    expect($names(['search' => ['value' => 'aweng']]))->toBe(['Lihua Soedarmo'])
        ->and($names(['search' => ['value' => '#7']]))->toBe(['Budi Santoso'])
        ->and($names(['status' => 'active']))->toBe(['Lihua Soedarmo'])
        ->and($names(['contact' => 'none']))->toBe(['Tanpa Kontak'])
        ->and($names(['order' => [['column' => 0, 'dir' => 'desc']]]))->toBe(['Tanpa Kontak', 'Budi Santoso', 'Lihua Soedarmo'])
        ->and($names(['length' => 10, 'start' => 10]))->toBe([]);

    $this->actingAs($this->admin)->getJson(route('members.data', dtQuery(['search' => ['value' => 'aweng']])))
        ->assertJsonPath('recordsTotal', 3)
        ->assertJsonPath('recordsFiltered', 1);
});

test('datatables endpoint rejects unsafe parameters', function (array $query, string $field) {
    $this->actingAs($this->admin)->getJson(route('members.data', dtQuery($query)))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unsortable column' => [['order' => [['column' => 2]]], 'order.0.column'],
    'bad direction' => [['order' => [['dir' => 'drop']]], 'order.0.dir'],
    'huge page length' => [['length' => 100000], 'length'],
    'unknown status' => [['status' => 'banned'], 'status'],
]);

test('datatables endpoint requires login', function () {
    $this->getJson(route('members.data', dtQuery()))->assertUnauthorized();
});

test('member detail page is displayed', function () {
    $member = Member::factory()->create(['nama_lengkap' => 'Arwani Hidayat']);

    $this->actingAs($this->admin)->get(route('members.show', $member))
        ->assertOk()->assertSee('Arwani Hidayat')->assertSee('Riwayat Iuran');
});

test('admin can set status manually and it survives automatic refresh', function () {
    $member = Member::factory()->create();
    $service = app(MembershipService::class);
    $service->waive($member, $service->currentTerm($member));
    expect($member->fresh()->status)->toBe('active');

    $this->actingAs($this->admin)->put(route('members.status.update', $member), [
        'status' => 'inactive',
        'reason' => 'Mengundurkan diri',
    ])->assertRedirect(route('members.show', $member))->assertSessionHas('success');

    $member->refresh();
    expect($member->status)->toBe('inactive')
        ->and($member->status_mode)->toBe('manual');

    // Perhitungan otomatis tidak menimpa status manual
    app(MembershipService::class)->refreshStatus($member);
    expect($member->fresh()->status)->toBe('inactive');

    $log = $member->statusLogs()->latest('id')->first();
    expect($log->to_status)->toBe('inactive')
        ->and($log->mode)->toBe('manual')
        ->and($log->reason)->toBe('Mengundurkan diri')
        ->and($log->changed_by)->toBe($this->admin->id);
});

test('reason is required for manual status', function () {
    $member = Member::factory()->create();

    $this->actingAs($this->admin)->put(route('members.status.update', $member), ['status' => 'active'])
        ->assertSessionHasErrors('reason');

    expect($member->fresh()->status_mode)->toBe('auto');
});

test('invalid status value is rejected', function () {
    $member = Member::factory()->create();

    $this->actingAs($this->admin)->put(route('members.status.update', $member), ['status' => 'banned', 'reason' => 'x'])
        ->assertSessionHasErrors('status');
});

test('admin can return status to automatic mode', function () {
    $member = Member::factory()->create();
    $service = app(MembershipService::class);
    $service->waive($member, $service->currentTerm($member));
    app(MembershipService::class)->setManualStatus($member, 'inactive', $this->admin, 'Test');

    $this->actingAs($this->admin)->put(route('members.status.update', $member), ['status' => 'auto'])
        ->assertRedirect();

    $member->refresh();
    expect($member->status_mode)->toBe('auto')
        ->and($member->status)->toBe('active'); // iuran periode berjalan dibebaskan → aktif lagi
});

test('user without CMS role cannot change member status', function () {
    $member = Member::factory()->create();

    $this->actingAs(User::factory()->create())
        ->put(route('members.status.update', $member), ['status' => 'active', 'reason' => 'x'])
        ->assertForbidden();

    expect($member->fresh()->status)->toBe('inactive');
});

test('member policy follows members.* permissions', function () {
    $editor = User::factory()->create();
    $member = Member::factory()->create();

    expect($editor->can('viewAny', Member::class))->toBeFalse();

    $editor->givePermissionTo('members.view');

    expect($editor->can('view', $member))->toBeTrue()
        ->and($editor->can('updateStatus', $member))->toBeFalse();
});

test('membership term follows join date and active_until adds grace days', function () {
    // Bergabung Februari 2026 → periode 2026-2027 = 10 Feb 2026 – 9 Feb 2027, daftar ulang Feb 2027
    $member = Member::factory()->create(['tgl_bergabung' => '2026-02-10']);
    $service = app(MembershipService::class);
    $term = $service->currentTerm($member, Carbon::parse('2026-10-08'));

    expect($term->label())->toBe('2026-2027')
        ->and($term->start->toDateString())->toBe('2026-02-10')
        ->and($term->end->toDateString())->toBe('2027-02-09');

    $due = $service->invoiceFor($member, $term);
    expect($due->invoice_number)->toBe(sprintf('IUR-2627-%04d', $member->no_lambung))
        ->and($due->period_label)->toBe('2026-2027')
        ->and($due->status)->toBe(MemberDue::UNPAID);

    $service->markPaid($due, 'transfer');
    $service->refreshStatus($member, Carbon::parse('2027-03-01'));

    $member->refresh();
    expect($member->paid_until->toDateString())->toBe('2027-02-09')
        ->and($member->renewalDate()->toDateString())->toBe('2027-02-10')
        ->and($member->active_until->toDateString())->toBe('2027-03-11')   // + 30 hari tenggang
        ->and($member->status)->toBe('active');

    // Periode yang harus dibayar berikutnya = 2027-2028 mulai Februari 2027
    $next = $service->renewalTerm($member);
    expect($next->label())->toBe('2027-2028')
        ->and($next->start->toDateString())->toBe('2027-02-10');

    $service->refreshStatus($member, Carbon::parse('2027-03-12'));
    expect($member->fresh()->status)->toBe('inactive');
});

test('datatables renewal filter returns members due soon and in grace period', function () {
    $this->travelTo(Carbon::parse('2027-02-01'));
    $service = app(MembershipService::class);

    $dueSoon = Member::factory()->create(['nama_lengkap' => 'Jatuh Tempo', 'tgl_bergabung' => '2025-02-10']);   // daftar ulang 10 Feb 2027
    $grace = Member::factory()->create(['nama_lengkap' => 'Tenggang', 'tgl_bergabung' => '2025-01-20']);       // daftar ulang 20 Jan 2027
    $far = Member::factory()->create(['nama_lengkap' => 'Masih Lama', 'tgl_bergabung' => '2025-08-01']);      // daftar ulang 1 Agu 2027

    foreach ([$dueSoon, $grace, $far] as $m) {
        $service->waive($m, $service->termForYear($m, 2026));
    }

    $names = fn (string $renewal) => $this->actingAs($this->admin)
        ->getJson(route('members.data', dtQuery(['renewal' => $renewal])))
        ->json('data.*.nama_lengkap');

    expect($names('due_soon'))->toBe(['Jatuh Tempo'])
        ->and($names('grace'))->toBe(['Tenggang']);

    $this->actingAs($this->admin)->getJson(route('members.data', dtQuery(['search' => ['value' => 'Jatuh']])))
        ->assertJsonPath('data.0.renewal_days_left', 9);
});
