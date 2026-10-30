<?php

use App\Models\Member;
use App\Models\MemberVehicle;
use App\Models\Setting;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Notifications\MemberRegistrationActivated;
use App\Notifications\MemberRegistrationPaymentLink;
use App\Notifications\NewMemberRegistration;
use App\Notifications\VerifyMemberRegistrationEmail;
use App\Payments\Gateways\FakeGateway;
use App\Services\MemberRegistrationService;
use App\Services\RegistrationPaymentToken;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.frontend.api_token' => 'test-token',
        'yodu.driver' => 'fake',
        'membership.annual_fee' => 300000,
        'membership.arrears_cutoff_year' => null,
        'membership.registration.verified_redirect' => 'http://fe.test/daftar/verifikasi',
        'membership.registration.payment_url' => 'http://fe.test/daftar/bayar',
        'membership.registration.payment_ttl_hours' => 24,
    ]);
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]); // kata sandi dianggap tidak bocor
    Storage::fake('local');
    Notification::fake();

    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');

    $this->brand = VehicleBrand::factory()->create(['name' => 'Wuling']);
    $this->vehicleModel = VehicleModel::factory()->create(['vehicle_brand_id' => $this->brand->id, 'name' => 'Air EV']);
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
        'vehicle_brand_id' => test()->brand->id,
        'vehicle_model_id' => test()->vehicleModel->id,
        'heard_from' => 'Instagram',
        'documents' => [UploadedFile::fake()->create('stnk.pdf', 200, 'application/pdf')],
    ], $overrides);
}

function register(array $overrides = [])
{
    return test()->withToken('test-token')->post('/api/v1/registrations', registrationPayload($overrides), ['Accept' => 'application/json']);
}

/** Daftar + klik link verifikasi → pendaftar menunggu pembayaran. */
function registerAndVerify(array $overrides = []): Member
{
    register($overrides)->assertCreated();
    $member = Member::firstWhere('email', strtolower($overrides['email'] ?? 'budi@example.com'));
    test()->get(VerifyMemberRegistrationEmail::verificationUrl($member));

    return $member->fresh();
}

function paymentToken(Member $member): string
{
    return RegistrationPaymentToken::make($member, $member->payment_due_at ?? now()->addDay());
}

function registrationApi(string $path, array $body)
{
    return test()->withToken('test-token')->postJson('/api/v1/registrations/payment'.$path, $body);
}

/** Tampilkan QRIS di halaman bayar, bayar lewat gateway palsu, lalu halaman bayar mendeteksi lewat polling. */
function payRegistration(Member $member): string
{
    $ref = registrationApi('/checkout', ['token' => paymentToken($member)])->assertCreated()->json('data.merchant_ref');
    test()->postJson("/api/payments/fake/{$ref}/pay?status=success")->assertOk();
    registrationApi('/status', ['token' => paymentToken($member), 'merchant_ref' => $ref])->assertOk();

    return $ref;
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
        ->and($vehicle->vehicle_model_id)->toBe($this->vehicleModel->id)
        ->and($vehicle->verification_status)->toBe(MemberVehicle::PENDING)
        ->and($vehicle->documents)->toHaveCount(1);

    Storage::disk('local')->assertExists($vehicle->documents->first()->path);
    Notification::assertSentTo($member, VerifyMemberRegistrationEmail::class);
});

test('chassis number and documents are optional, plate, brand and model are required', function () {
    register(['vin' => '', 'documents' => []])->assertCreated();
    register(['email' => 'sari@example.com', 'no_hp' => '081299998888', 'plat_nomor' => '5678', 'vin' => '', 'documents' => []])
        ->assertCreated();

    expect(MemberVehicle::whereNull('vin')->count())->toBe(2)   // banyak kendaraan tanpa No. rangka boleh
        ->and(MemberVehicle::first()->documents)->toHaveCount(0);

    register(['email' => 'dewi@example.com', 'no_hp' => '081277776666', 'plat_nomor' => '', 'vehicle_brand_id' => '', 'vehicle_model_id' => '', 'vin' => 'PENDEK'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['plat_nomor', 'vehicle_brand_id', 'vehicle_model_id', 'vin']);
});

test('registration validates password strength and duplicates', function () {
    Member::factory()->create(['email' => 'budi@example.com']);

    register(['password' => 'lemah', 'password_confirmation' => 'lemah'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

test('a stale unverified registration does not block registering again', function () {
    register()->assertCreated();
    register(['nama_lengkap' => 'Budi Baru'])->assertCreated();

    expect(Member::where('email', 'budi@example.com')->pluck('nama_lengkap')->all())->toBe(['Budi Baru'])
        ->and(MemberVehicle::count())->toBe(1);
});

test('unverified and unpaid registrants cannot log in', function () {
    register()->assertCreated();
    $login = fn () => $this->withToken('test-token')->postJson('/api/v1/member/login', ['identifier' => 'budi@example.com', 'password' => 'Rahasia123']);

    $login()->assertUnprocessable()
        ->assertJsonPath('errors.identifier.0', 'E-mail Anda belum diverifikasi. Cek kotak masuk e-mail untuk link verifikasi.');

    $this->get(VerifyMemberRegistrationEmail::verificationUrl(Member::firstWhere('email', 'budi@example.com')));

    $login()->assertUnprocessable()
        ->assertJsonPath('errors.identifier.0', fn (string $message) => str_starts_with($message, 'Selesaikan pembayaran iuran pendaftaran'));
});

test('verification link moves the registrant to payment and opens the payment page', function () {
    register()->assertCreated();
    $member = Member::firstWhere('email', 'budi@example.com');

    $response = $this->get(VerifyMemberRegistrationEmail::verificationUrl($member));

    $member->refresh();
    expect($member->registration_status)->toBe(Member::REG_AWAITING_PAYMENT)
        ->and($member->email_verified_at)->not->toBeNull()
        ->and($member->payment_due_at->between(now()->addHours(23), now()->addHours(25)))->toBeTrue();

    $location = $response->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith('http://fe.test/daftar/bayar?token=');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect(RegistrationPaymentToken::member($query['token'])?->is($member))->toBeTrue();

    // Pengurus baru dikabari setelah pendaftar membayar
    Notification::assertNotSentTo($this->admin, NewMemberRegistration::class);

    // Klik ulang → tetap ke halaman bayar
    expect($this->get(VerifyMemberRegistrationEmail::verificationUrl($member))->headers->get('Location'))
        ->toStartWith('http://fe.test/daftar/bayar?token=');
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

test('payment page token: forged, expired or for another member is rejected', function () {
    $member = registerAndVerify();
    $token = paymentToken($member);

    registrationApi('', ['token' => $token])->assertOk()
        ->assertJsonPath('data.status', 'awaiting_payment')
        ->assertJsonPath('data.name', 'Budi')
        ->assertJsonPath('data.amount', 300000)
        ->assertJsonPath('data.no_lambung', null)
        ->assertJsonMissingPath('data.email');

    [$id, $expires, $signature] = explode('.', $token);
    registrationApi('', ['token' => "{$id}.".($expires + 3600).".{$signature}"])->assertNotFound(); // diperpanjang sendiri
    registrationApi('', ['token' => ($id + 1).".{$expires}.{$signature}"])->assertNotFound();      // milik orang lain
    registrationApi('', ['token' => 'bukan-token'])->assertNotFound();
    registrationApi('', [])->assertUnprocessable();

    $this->travel(25)->hours();
    registrationApi('', ['token' => $token])->assertNotFound();
});

test('paying the registration fee activates the member with the next free number', function () {
    Setting::put(Setting::NO_LAMBUNG_START, '309');
    foreach ([1, 308, 309, 310, 370] as $number) {
        Member::factory()->create(['no_lambung' => $number]);
    }
    Member::factory()->create(['no_lambung' => 311])->delete(); // nomor anggota yang dihapus tetap terpakai

    $member = registerAndVerify();
    $ref = payRegistration($member);

    $member->refresh();
    expect($ref)->toStartWith('KLKR'.str_pad((string) $member->id, 5, '0', STR_PAD_LEFT).'-')
        ->and($member->registration_status)->toBe(Member::REG_APPROVED)
        ->and($member->no_lambung)->toBe(312)
        ->and($member->status)->toBe(Member::STATUS_ACTIVE)
        ->and($member->payment_due_at)->toBeNull()
        ->and($member->tgl_bergabung->isToday())->toBeTrue()
        ->and($member->dues()->first()->invoice_number)->toEndWith('-0312');

    Notification::assertSentTo($member, MemberRegistrationActivated::class);
    Notification::assertSentTo($this->admin, NewMemberRegistration::class);

    // Halaman bayar menampilkan status aktif & No. lambung; tidak bisa bayar lagi
    registrationApi('', ['token' => paymentToken($member)])->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.no_lambung', '#312');
    registrationApi('/status', ['token' => paymentToken($member), 'merchant_ref' => $ref])->assertOk()
        ->assertJsonPath('data.status', 'success')
        ->assertJsonPath('data.registration.no_lambung', '#312');
    registrationApi('/checkout', ['token' => paymentToken($member)])->assertStatus(409);

    // Sekarang bisa login & tampil di daftar member CMS
    $this->withToken('test-token')->postJson('/api/v1/member/login', ['identifier' => 'budi@example.com', 'password' => 'Rahasia123'])
        ->assertOk();
    $this->actingAs($this->admin)->get(route('members.index'))
        ->assertViewHas('summary', fn (array $s) => $s['total'] === 6); // 5 anggota lama + pendaftar baru
});

test('without a start number the next number is the highest plus one', function () {
    Member::factory()->create(['no_lambung' => 905]);
    $member = registerAndVerify();

    payRegistration($member);

    expect($member->fresh()->no_lambung)->toBe(906);
});

test('a registrant cannot see another registrant payment', function () {
    $budi = registerAndVerify();
    $ref = registrationApi('/checkout', ['token' => paymentToken($budi)])->json('data.merchant_ref');
    $sari = registerAndVerify(['email' => 'sari@example.com', 'no_hp' => '081299998888', 'plat_nomor' => '5678', 'vin' => '']);

    registrationApi('/status', ['token' => paymentToken($sari), 'merchant_ref' => $ref])->assertNotFound();
});

test('registrants are hidden from the CMS until they pay', function () {
    $member = registerAndVerify();

    $this->actingAs($this->admin)->get(route('members.index'))
        ->assertOk()
        ->assertViewHas('summary', fn (array $s) => $s['total'] === 0);
    $this->actingAs($this->admin)->get(route('members.show', $member))->assertNotFound();
});

test('resend sends the verification link or the payment link, silently for unknown e-mails', function () {
    register()->assertCreated();
    $resend = fn (string $email) => $this->withToken('test-token')
        ->postJson('/api/v1/registrations/resend-verification', ['email' => $email])->assertOk();

    $resend('budi@example.com');
    Notification::assertSentToTimes(Member::firstWhere('email', 'budi@example.com'), VerifyMemberRegistrationEmail::class, 2);

    $member = registerAndVerify(['email' => 'sari@example.com', 'no_hp' => '081299998888', 'plat_nomor' => '5678', 'vin' => '']);
    $this->travel(20)->hours();
    $resend('sari@example.com');

    Notification::assertSentTo($member, MemberRegistrationPaymentLink::class);
    expect($member->fresh()->payment_due_at->greaterThan(now()->addHours(23)))->toBeTrue(); // batas diperpanjang

    $resend('tidak-ada@example.com');
});

test('re-registering is blocked while the previous registration has a payment in flight', function () {
    $member = registerAndVerify();
    registrationApi('/checkout', ['token' => paymentToken($member)])->assertCreated();

    register(['nama_lengkap' => 'Budi Baru'])->assertUnprocessable()->assertJsonValidationErrors('email');

    $this->travel(2)->hours(); // QR lama sudah lama kedaluwarsa → boleh daftar ulang
    register(['nama_lengkap' => 'Budi Baru'])->assertCreated();
});

test('unverified and unpaid registrations are pruned, but a late payment activates instead', function () {
    register()->assertCreated();
    $path = MemberVehicle::first()->documents()->first()->path;
    $unpaid = registerAndVerify(['email' => 'sari@example.com', 'no_hp' => '081299998888', 'plat_nomor' => '5678', 'vin' => '']);
    $paidLate = registerAndVerify(['email' => 'dewi@example.com', 'no_hp' => '081277776666', 'plat_nomor' => '9012', 'vin' => '']);
    $ref = registrationApi('/checkout', ['token' => paymentToken($paidLate)])->json('data.merchant_ref');
    Cache::forever(FakeGateway::cacheKey($ref), 'success'); // dibayar, tapi callback & polling tidak pernah masuk

    $this->travel(8)->days();
    $this->artisan('members:prune-registrations')->assertSuccessful();

    expect(Member::withTrashed()->where('email', 'budi@example.com')->exists())->toBeFalse()
        ->and(Member::withTrashed()->whereKey($unpaid->id)->exists())->toBeFalse()
        ->and($paidLate->fresh()->registration_status)->toBe(Member::REG_APPROVED);
    Storage::disk('local')->assertMissing($path);
});

test('notification e-mails render with working links', function () {
    $member = registerAndVerify();

    expect((string) (new VerifyMemberRegistrationEmail)->toMail($member)->render())->toContain('signature=')
        ->and((string) (new MemberRegistrationPaymentLink)->toMail($member)->render())->toContain('daftar/bayar?token=')
        ->and((string) (new MemberRegistrationPaymentLink)->toMail($member)->render())->toContain('Rp300.000');

    payRegistration($member);
    $member->refresh();

    expect((string) (new MemberRegistrationActivated)->toMail($member)->render())->toContain($member->noLambungLabel())
        ->and((string) (new NewMemberRegistration($member))->toMail($this->admin)->render())->toContain(route('members.show', $member));
});

test('admin bell lists the new member and opening it goes to the member page', function () {
    $member = registerAndVerify();
    payRegistration($member);

    // Notification di-fake → simpan sungguhan ke tabel notifications lewat channel database
    (new ChannelManager(app()))->sendNow($this->admin, new NewMemberRegistration($member->fresh()), ['database']);
    $notification = $this->admin->notifications()->firstOrFail();

    $this->actingAs($this->admin)->get(route('notifications.open', $notification->id))
        ->assertRedirect(route('members.show', $member));
});

test('pending registrations from the old review flow can be converted to the payment flow', function () {
    register()->assertCreated();
    $member = Member::firstWhere('email', 'budi@example.com');
    $member->forceFill(['registration_status' => Member::REG_PENDING, 'email_verified_at' => now()])->save();

    $this->artisan('members:convert-pending-registrations')->assertSuccessful();

    expect($member->fresh()->registration_status)->toBe(Member::REG_AWAITING_PAYMENT)
        ->and($member->fresh()->payment_due_at)->not->toBeNull();
    Notification::assertSentTo($member, MemberRegistrationPaymentLink::class);
    expect(app(MemberRegistrationService::class)->paymentUrl($member->fresh()))->toStartWith('http://fe.test/daftar/bayar?token=');
});
