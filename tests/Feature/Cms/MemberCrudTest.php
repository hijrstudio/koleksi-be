<?php

use App\Http\Requests\Cms\MemberRequest;
use App\Models\Member;
use App\Models\MemberDue;
use App\Models\MemberVehicle;
use App\Models\Setting;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Notifications\ResetMemberPassword;
use App\Services\MembershipService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['membership.annual_fee' => 300000, 'membership.arrears_cutoff_year' => null]);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');

    DB::table('provinces')->insert(['code' => '31', 'name' => 'DKI JAKARTA']);
    DB::table('regencies')->insert(['code' => '31.74', 'province_code' => '31', 'name' => 'KOTA JAKARTA SELATAN']);
    DB::table('districts')->insert(['code' => '31.74.01', 'regency_code' => '31.74', 'name' => 'TEBET']);
    DB::table('villages')->insert(['code' => '31.74.01.1001', 'district_code' => '31.74.01', 'name' => 'TEBET TIMUR']);

    $this->brand = VehicleBrand::factory()->create();
    $this->model = VehicleModel::factory()->create(['vehicle_brand_id' => $this->brand->id]);
});

function memberPayload(array $overrides = []): array
{
    return array_merge([
        'nama_lengkap' => 'Siti Aminah',
        'nama_panggilan' => 'Siti',
        'email' => 'Siti@Example.com',
        'no_hp' => '+62 812-3456-7890',
        'tgl_lahir' => '1990-05-01',
        'jenis_kelamin' => 'P',
        'alamat' => 'Jl. Contoh 1',
        'city_code' => '31.74',
        'district_code' => '31.74.01',
        'village_code' => '31.74.01.1001',
        'tgl_bergabung' => today()->toDateString(),
        'notes' => 'Daftar di acara kopdar',
        'has_vehicle' => '1',
        'plat_kode' => 'b',
        'plat_nomor' => '1234',
        'plat_seri' => 'xyz',
        'plat_status' => 'permanen',
        'vehicle_brand_id' => null,
        'vehicle_model_id' => null,
        'first_due' => 'paid',
        'payment_method' => 'transfer',
        'password_mode' => 'email',
    ], $overrides);
}

test('admin adds a paid member: next free number, active, vehicle verified, set-password link sent', function () {
    Notification::fake();
    Member::factory()->create(['no_lambung' => 10]);
    Setting::put(Setting::NO_LAMBUNG_START, '10');

    $this->actingAs($this->admin)->get(route('members.create'))->assertOk()->assertSee('Otomatis: #011');

    $payload = memberPayload(['vehicle_brand_id' => $this->brand->id, 'vehicle_model_id' => $this->model->id]);
    $response = $this->actingAs($this->admin)->post(route('members.store'), $payload);

    $member = Member::where('email', 'siti@example.com')->firstOrFail();
    $response->assertRedirect(route('members.show', $member));

    $vehicle = $member->primaryVehicle();
    $due = $member->dues()->first();
    expect($member->no_lambung)->toBe(11)
        ->and($member->no_hp)->toBe('081234567890')
        ->and($member->isRegistrationApproved())->toBeTrue()
        ->and($member->status)->toBe(Member::STATUS_ACTIVE)
        ->and($member->must_change_password)->toBeFalse()
        ->and($member->email_verified_at)->not->toBeNull()
        ->and($member->profile_completed_at)->not->toBeNull()
        ->and($vehicle->plat_display)->toBe('B 1234 XYZ')
        ->and($vehicle->verification_status)->toBe(MemberVehicle::VERIFIED)
        ->and($vehicle->verified_by)->toBe($this->admin->id)
        ->and($due->status)->toBe(MemberDue::PAID)
        ->and($due->payment_method)->toBe('transfer')
        ->and($due->recorded_by)->toBe($this->admin->id)
        ->and($due->invoice_number)->toEndWith('-0011')
        ->and($member->statusLogs()->where('reason', 'Member ditambahkan dari CMS')->exists())->toBeTrue();

    Notification::assertSentTo($member, ResetMemberPassword::class);
});

test('admin adds an unpaid member with a chosen number and no contact', function () {
    Notification::fake();

    $this->actingAs($this->admin)->post(route('members.store'), memberPayload([
        'no_lambung' => 500, 'email' => '', 'no_hp' => '', 'has_vehicle' => '0', 'first_due' => 'unpaid',
        'password_mode' => 'admin', 'password' => 'Rahasia123', 'password_confirmation' => 'Rahasia123',
    ]))->assertSessionHasNoErrors();

    $member = Member::where('no_lambung', 500)->firstOrFail();
    expect($member->status)->toBe(Member::STATUS_INACTIVE)
        ->and($member->email)->toBeNull()
        ->and($member->vehicles()->exists())->toBeFalse()
        ->and($member->dues()->first()->status)->toBe(MemberDue::UNPAID)
        ->and(Hash::check('Rahasia123', $member->password))->toBeTrue()
        ->and($member->must_change_password)->toBeTrue();

    Notification::assertNothingSent();
});

test('a first due paid in cash is stored with the payment method the database accepts', function () {
    $this->actingAs($this->admin)->post(route('members.store'), memberPayload(['payment_method' => 'cash']))->assertSessionHasNoErrors();

    // Nilai harus ada di enum member_dues.payment_method (MySQL menolak nilai lain; SQLite tes tidak)
    expect(Member::where('email', 'siti@example.com')->firstOrFail()->dues()->first()->payment_method)->toBe('cash')
        ->and(array_keys(MemberRequest::PAYMENT_METHODS))
        ->each->toBeIn(['online', 'cash', 'transfer', 'waived', 'amnesty']);

    $this->actingAs($this->admin)->post(route('members.store'), memberPayload(['payment_method' => 'tunai', 'plat_nomor' => '9999']))
        ->assertSessionHasErrors('payment_method');
});

test('a waived first due makes the member active', function () {
    $this->actingAs($this->admin)->post(route('members.store'), memberPayload(['first_due' => 'waived']))->assertSessionHasNoErrors();

    $member = Member::where('email', 'siti@example.com')->firstOrFail();
    expect($member->status)->toBe(Member::STATUS_ACTIVE)
        ->and($member->dues()->first()->status)->toBe(MemberDue::WAIVED);
});

test('adding refuses taken numbers, contacts, plates and invalid regions', function () {
    $other = Member::factory()->create(['no_lambung' => 7, 'email' => 'taken@example.com', 'no_hp' => '081200000000']);
    $other->delete(); // juga milik member yang dihapus
    MemberVehicle::create(['member_id' => Member::factory()->create()->id, 'plat_kode' => 'B', 'plat_nomor' => '1234', 'plat_seri' => 'XYZ', 'plat_status' => 'permanen']);

    $this->actingAs($this->admin)->post(route('members.store'), memberPayload([
        'no_lambung' => 7, 'email' => 'taken@example.com', 'no_hp' => '081200000000',
    ]))->assertSessionHasErrors(['email', 'no_hp']);

    $this->actingAs($this->admin)->post(route('members.store'), memberPayload(['no_lambung' => 7, 'plat_nomor' => '9999']))
        ->assertSessionHasErrors('no_lambung');

    $this->actingAs($this->admin)->post(route('members.store'), memberPayload())
        ->assertSessionHasErrors('plat_nomor');

    $this->actingAs($this->admin)->post(route('members.store'), memberPayload([
        'plat_nomor' => '9999', 'district_code' => '99.99.99', 'tgl_bergabung' => today()->addDay()->toDateString(),
    ]))->assertSessionHasErrors(['district_code', 'tgl_bergabung']);

    $this->actingAs($this->admin)->post(route('members.store'), memberPayload(['plat_nomor' => '9999', 'email' => '']))
        ->assertSessionHasErrors('password_mode');

    // Kata sandi buatan admin: aturan sama dengan kata sandi member, dan harus dikonfirmasi
    foreach (['Pdk1A', 'semuakecil123', 'Rahasia123'] as $i => $password) {
        $this->actingAs($this->admin)->post(route('members.store'), memberPayload([
            'plat_nomor' => '9999', 'password_mode' => 'admin', 'password' => $password,
            'password_confirmation' => $i === 2 ? 'Beda12345' : $password,
        ]))->assertSessionHasErrors('password');
    }
    $this->actingAs($this->admin)->post(route('members.store'), memberPayload(['plat_nomor' => '9999', 'password_mode' => 'keep']))
        ->assertSessionHasErrors('password_mode');

    expect(Member::where('nama_lengkap', 'Siti Aminah')->exists())->toBeFalse();
});

test('admin edits every field; changes are logged and the number change renumbers invoices', function () {
    $member = Member::factory()->create(['no_lambung' => 42, 'nama_lengkap' => 'Budi', 'tgl_bergabung' => today(), 'has_vehicle' => false]);
    $membership = app(MembershipService::class);
    $due = $membership->invoiceFor($member, $membership->currentTerm($member));

    $this->actingAs($this->admin)->get(route('members.edit', $member))->assertOk()->assertSee('Budi');

    $this->actingAs($this->admin)->put(route('members.update', $member), memberPayload([
        'no_lambung' => 77, 'nama_lengkap' => 'Budi Santoso', 'email' => 'budi@example.com',
        'vehicle_brand_id' => $this->brand->id, 'vehicle_model_id' => $this->model->id, 'password_mode' => 'keep',
    ]))->assertRedirect(route('members.show', $member));

    $member->refresh();
    $log = $member->statusLogs()->latest('id')->first();
    expect($member->no_lambung)->toBe(77)
        ->and($member->nama_lengkap)->toBe('Budi Santoso')
        ->and($member->village_code)->toBe('31.74.01.1001')
        ->and($member->primaryVehicle()->vehicle_model_id)->toBe($this->model->id)
        ->and($due->fresh()->invoice_number)->toEndWith('-0077')
        ->and($log->changed_by)->toBe($this->admin->id)
        ->and($log->reason)->toContain('nama lengkap')
        ->and($log->reason)->toContain('kendaraan')
        ->and($log->reason)->toContain('No. lambung #042 → #077');
});

test('on edit the admin keeps the password, sets a temporary one, or e-mails a set-password link', function () {
    Notification::fake();
    $member = Member::factory()->create(['no_lambung' => 42, 'email' => 'budi@example.com', 'password' => 'LamaSekali1', 'must_change_password' => false]);
    $token = $member->createToken('member', ['member'])->plainTextToken;
    $form = fn (array $overrides) => memberPayload(['no_lambung' => 42, 'email' => 'budi@example.com', 'no_hp' => '', 'has_vehicle' => '0'] + $overrides);

    $this->actingAs($this->admin)->put(route('members.update', $member), $form(['password_mode' => 'keep']))->assertSessionHasNoErrors();
    expect(Hash::check('LamaSekali1', $member->fresh()->password))->toBeTrue();

    $this->actingAs($this->admin)->put(route('members.update', $member), $form([
        'password_mode' => 'admin', 'password' => 'Sementara99', 'password_confirmation' => 'Sementara99',
    ]))->assertSessionHasNoErrors();

    $member->refresh();
    $log = $member->statusLogs()->latest('id')->first();
    expect(Hash::check('Sementara99', $member->password))->toBeTrue()
        ->and($member->must_change_password)->toBeTrue()
        ->and($member->tokens()->count())->toBe(0)
        ->and($log->reason)->toContain('kata sandi')
        ->and($log->reason)->not->toContain('Sementara99');
    $this->withToken($token)->getJson('/api/v1/member/me')->assertUnauthorized();

    $this->actingAs($this->admin)->put(route('members.update', $member), $form(['password_mode' => 'email']))->assertSessionHasNoErrors();
    Notification::assertSentTo($member, ResetMemberPassword::class);
    expect($member->statusLogs()->latest('id')->first()->reason)->toBe('Link atur kata sandi dikirim ke e-mail member dari CMS');
});

test('the join date is locked once the member has dues', function () {
    $member = Member::factory()->create(['no_lambung' => 42, 'tgl_bergabung' => today()->subMonths(3)]);
    $membership = app(MembershipService::class);
    $membership->invoiceFor($member, $membership->currentTerm($member));

    $this->actingAs($this->admin)->put(route('members.update', $member), memberPayload([
        'no_lambung' => 42, 'tgl_bergabung' => today()->toDateString(), 'password_mode' => 'keep',
    ]))->assertSessionHasErrors('tgl_bergabung');

    $this->actingAs($this->admin)->put(route('members.update', $member), memberPayload([
        'no_lambung' => 42, 'tgl_bergabung' => today()->subMonths(3)->toDateString(), 'password_mode' => 'keep',
    ]))->assertSessionHasNoErrors();
});

test('admins without the permissions cannot add or edit members', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('members.view');
    $member = Member::factory()->create(['no_lambung' => 42]);

    expect($viewer->can('create', Member::class))->toBeFalse()
        ->and($viewer->can('update', $member))->toBeFalse();
    $this->actingAs($viewer)->get(route('members.create'))->assertForbidden();
    $this->actingAs($viewer)->post(route('members.store'), memberPayload())->assertForbidden();
    $this->actingAs($viewer)->put(route('members.update', $member), memberPayload(['no_lambung' => 42, 'password_mode' => 'keep']))->assertForbidden();

    $this->actingAs($this->admin)->get(route('members.index'))->assertSee(route('members.create'));
    expect(Member::count())->toBe(1);
});

test('region dropdowns load districts and villages for admins only', function () {
    $this->actingAs($this->admin)->getJson(route('members.regions.districts', '31.74'))
        ->assertOk()->assertJsonPath('data.0.name', 'TEBET');
    $this->actingAs($this->admin)->getJson(route('members.regions.villages', '31.74.01'))
        ->assertOk()->assertJsonPath('data.0.code', '31.74.01.1001');

    $this->actingAs(User::factory()->create())->getJson(route('members.regions.districts', '31.74'))->assertForbidden();
});

test('the delete confirmation encodes the member name safely', function () {
    $member = Member::factory()->create(['no_lambung' => 42, 'nama_lengkap' => "x'); alert(1);//"]);

    $this->actingAs($this->admin)->get(route('members.show', $member))
        ->assertOk()
        ->assertDontSee("confirm('Hapus member x'); alert(1);//", false);
});
