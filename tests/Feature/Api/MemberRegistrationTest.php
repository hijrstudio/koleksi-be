<?php

use App\Models\Member;
use App\Models\MemberVehicle;
use App\Models\User;
use App\Notifications\MemberRegistrationApproved;
use App\Notifications\MemberRegistrationRejected;
use App\Notifications\NewMemberRegistration;
use App\Notifications\VerifyMemberRegistrationEmail;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.frontend.api_token' => 'test-token',
        'membership.annual_fee' => 300000,
        'membership.registration.verified_redirect' => 'http://fe.test/daftar/verifikasi',
    ]);
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]); // kata sandi dianggap tidak bocor
    Storage::fake('local');
    Notification::fake();

    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'Budi@Example.com',
        'password' => 'Rahasia123',
        'password_confirmation' => 'Rahasia123',
        'nama_lengkap' => 'Budi Santoso',
        'nama_panggilan' => 'Budi',
        'no_hp' => '0812 3456 7890',
        'has_vehicle' => '1',
        'vin' => 'abcdefg1234567890',
        'plat_kode' => 'b',
        'plat_nomor' => '1234',
        'plat_seri' => 'xyz',
        'plat_status' => 'permanen',
        'heard_from' => 'Instagram',
        'documents' => [UploadedFile::fake()->create('stnk.pdf', 200, 'application/pdf')],
    ], $overrides);
}

function register(array $overrides = [])
{
    return test()->withToken('test-token')->post('/api/v1/registrations', registrationPayload($overrides), ['Accept' => 'application/json']);
}

test('registration requires the frontend token', function () {
    $this->postJson('/api/v1/registrations', registrationPayload())->assertUnauthorized();
});

test('registration stores an unverified member with vehicle and documents, then sends a verification link', function () {
    register()->assertCreated()->assertJsonPath('data.email', 'budi@example.com');

    $member = Member::firstWhere('email', 'budi@example.com');
    $vehicle = $member->vehicles()->first();

    expect($member->registration_status)->toBe(Member::REG_UNVERIFIED)
        ->and($member->no_lambung)->toBeNull()
        ->and($member->no_hp)->toBe('081234567890')
        ->and($member->status)->toBe(Member::STATUS_INACTIVE)
        ->and($member->must_change_password)->toBeFalse()
        ->and($member->heard_from)->toBe('Instagram')
        ->and($vehicle->plat_full)->toBe('B1234XYZ')
        ->and($vehicle->verification_status)->toBe(MemberVehicle::PENDING)
        ->and($vehicle->documents)->toHaveCount(1);

    Storage::disk('local')->assertExists($vehicle->documents->first()->path);
    Notification::assertSentTo($member, VerifyMemberRegistrationEmail::class);
});

test('registration validates password strength, duplicates and documents', function () {
    Member::factory()->create(['email' => 'budi@example.com']);

    register(['password' => 'lemah', 'password_confirmation' => 'lemah', 'documents' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password', 'documents']);
});

test('a stale unverified registration does not block registering again', function () {
    register()->assertCreated();
    register(['nama_lengkap' => 'Budi Baru'])->assertCreated();

    expect(Member::where('email', 'budi@example.com')->pluck('nama_lengkap')->all())->toBe(['Budi Baru'])
        ->and(MemberVehicle::count())->toBe(1);
});

test('unverified and pending registrants cannot log in', function () {
    register()->assertCreated();

    $this->withToken('test-token')->postJson('/api/v1/member/login', ['identifier' => 'budi@example.com', 'password' => 'Rahasia123'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.identifier.0', 'E-mail Anda belum diverifikasi. Cek kotak masuk e-mail untuk link verifikasi.');
});

test('verification link moves the registration to review and notifies admins', function () {
    register()->assertCreated();
    $member = Member::firstWhere('email', 'budi@example.com');

    $this->get(VerifyMemberRegistrationEmail::verificationUrl($member))
        ->assertRedirect('http://fe.test/daftar/verifikasi?status=verified');

    expect($member->fresh()->registration_status)->toBe(Member::REG_PENDING)
        ->and($member->fresh()->email_verified_at)->not->toBeNull();
    Notification::assertSentTo($this->admin, NewMemberRegistration::class);

    // Klik ulang → tetap sukses, admin tidak dikabari dobel
    $this->get(VerifyMemberRegistrationEmail::verificationUrl($member))
        ->assertRedirect('http://fe.test/daftar/verifikasi?status=already');
    Notification::assertSentToTimes($this->admin, NewMemberRegistration::class, 1);
});

test('tampered or expired verification links are rejected', function () {
    register()->assertCreated();
    $member = Member::firstWhere('email', 'budi@example.com');
    $url = VerifyMemberRegistrationEmail::verificationUrl($member);

    $this->get(str_replace('signature=', 'signature=x', $url))
        ->assertRedirect('http://fe.test/daftar/verifikasi?status=invalid');

    $this->travel(2)->days();
    $this->get($url)->assertRedirect('http://fe.test/daftar/verifikasi?status=expired');

    expect($member->fresh()->registration_status)->toBe(Member::REG_UNVERIFIED);
});

test('admin approves a registration: number assigned, member notified and can pay the first dues', function () {
    Member::factory()->create(['no_lambung' => 41]);
    register()->assertCreated();
    $member = Member::firstWhere('email', 'budi@example.com');
    $this->get(VerifyMemberRegistrationEmail::verificationUrl($member));

    $this->actingAs($this->admin)
        ->get(route('member-registrations.index'))
        ->assertOk()
        ->assertSee('Budi Santoso');

    $document = $member->vehicles()->first()->documents()->first();
    $this->actingAs($this->admin)
        ->get(route('member-registrations.show', $member))
        ->assertOk()
        ->assertSee('Setujui Pendaftaran')
        ->assertSee(route('member-documents.show', $document));
    $this->actingAs($this->admin)->get(route('member-documents.show', $document))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->actingAs($this->admin)
        ->post(route('member-registrations.approve', $member))
        ->assertRedirect(route('member-registrations.show', $member));
    $this->actingAs($this->admin)
        ->get(route('member-registrations.show', $member))
        ->assertOk()
        ->assertSee('#042')
        ->assertDontSee('Setujui Pendaftaran');

    $member->refresh();
    expect($member->registration_status)->toBe(Member::REG_APPROVED)
        ->and($member->no_lambung)->toBe(42)
        ->and($member->tgl_bergabung->isToday())->toBeTrue()
        ->and($member->reviewed_by)->toBe($this->admin->id)
        ->and($member->status)->toBe(Member::STATUS_INACTIVE)
        ->and($member->vehicles()->first()->verification_status)->toBe(MemberVehicle::VERIFIED);
    Notification::assertSentTo($member, MemberRegistrationApproved::class);

    // Bisa login, dan kartu iuran di dasbor menagih iuran pertama Rp300.000
    $this->withToken('test-token')->postJson('/api/v1/member/login', ['identifier' => 'budi@example.com', 'password' => 'Rahasia123'])
        ->assertOk();
    Sanctum::actingAs($member, ['member']);
    $this->getJson('/api/v1/member/me')
        ->assertOk()
        ->assertJsonPath('data.billing.state', 'unpaid')
        ->assertJsonPath('data.billing.total', 300000);

    // Sudah direview → tidak bisa disetujui dua kali
    $this->actingAs($this->admin)->post(route('member-registrations.approve', $member))->assertSessionHasErrors('registration');
});

test('admin rejects a registration with a reason', function () {
    register()->assertCreated();
    $member = Member::firstWhere('email', 'budi@example.com');
    $this->get(VerifyMemberRegistrationEmail::verificationUrl($member));

    $this->actingAs($this->admin)
        ->post(route('member-registrations.reject', $member), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->admin)
        ->post(route('member-registrations.reject', $member), ['reason' => 'Dokumen STNK tidak terbaca.'])
        ->assertRedirect();

    expect($member->fresh()->registration_status)->toBe(Member::REG_REJECTED)
        ->and($member->fresh()->review_note)->toBe('Dokumen STNK tidak terbaca.');
    Notification::assertSentTo($member, MemberRegistrationRejected::class);

    // Boleh mendaftar ulang dengan data yang sama
    register()->assertCreated();
});

test('registrants are hidden from the member list until approved', function () {
    register()->assertCreated();

    $this->actingAs($this->admin)->get(route('members.index'))
        ->assertOk()
        ->assertViewHas('summary', fn (array $s) => $s['total'] === 0);
});

test('users without a CMS role cannot review registrations', function () {
    register()->assertCreated();
    $member = Member::firstWhere('email', 'budi@example.com');
    $this->get(VerifyMemberRegistrationEmail::verificationUrl($member));

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('members.view');

    $this->actingAs($viewer)->get(route('member-registrations.show', $member))->assertForbidden();
    $this->actingAs($viewer)->post(route('member-registrations.approve', $member))->assertForbidden();
    expect($member->fresh()->registration_status)->toBe(Member::REG_PENDING);
});

test('admin bell lists the new registration and opening it marks it read', function () {
    register()->assertCreated();
    $member = Member::firstWhere('email', 'budi@example.com');
    $this->get(VerifyMemberRegistrationEmail::verificationUrl($member));

    // Notification di-fake → simpan sungguhan ke tabel notifications lewat channel database
    (new ChannelManager(app()))->sendNow($this->admin, new NewMemberRegistration($member), ['database']);
    $notification = $this->admin->notifications()->firstOrFail();

    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Budi Santoso menunggu review pendaftaran.')
        ->assertSee(route('notifications.open', $notification->id));

    $this->actingAs($this->admin)->get(route('notifications.open', $notification->id))
        ->assertRedirect(route('member-registrations.show', $member));
    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('notification e-mails render with working links', function () {
    register()->assertCreated();
    $member = Member::firstWhere('email', 'budi@example.com');

    expect((string) (new VerifyMemberRegistrationEmail)->toMail($member)->render())->toContain('signature=')
        ->and((string) (new NewMemberRegistration($member))->toMail($this->admin)->render())->toContain(route('member-registrations.show', $member))
        ->and((string) (new MemberRegistrationApproved)->toMail($member)->render())->toContain('Rp300.000');
});

test('unverified registrations are pruned after the configured days', function () {
    register()->assertCreated();
    $path = MemberVehicle::first()->documents()->first()->path;

    $this->travel(8)->days();
    $this->artisan('members:prune-registrations')->assertSuccessful();

    expect(Member::withTrashed()->count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});
