<?php

use App\Jobs\SendMemberPasswordResetLink;
use App\Models\Member;
use App\Notifications\MemberPasswordChanged;
use App\Notifications\ResetMemberPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.frontend.api_token' => 'test-token',
        'membership.password_reset_url' => 'http://fe.test/lupa-password/atur-ulang',
    ]);
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]); // kata sandi dianggap tidak bocor
    Notification::fake();

    $this->member = Member::factory()->create(['email' => 'budi@example.com', 'password' => 'LamaSekali1']);
});

function forgot(string $email)
{
    return test()->withToken('test-token')->postJson('/api/v1/member/password/forgot', ['email' => $email]);
}

/** Token dari e-mail yang terakhir dikirim ke member. */
function sentResetToken(Member $member): string
{
    $token = null;
    Notification::assertSentTo($member, ResetMemberPassword::class, function (ResetMemberPassword $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    return $token;
}

function resetPassword(array $payload)
{
    return test()->withToken('test-token')->postJson('/api/v1/member/password/reset', array_merge([
        'email' => 'budi@example.com',
        'password' => 'BaruSekali2',
        'password_confirmation' => 'BaruSekali2',
    ], $payload));
}

test('password reset endpoints require the frontend token', function () {
    $this->postJson('/api/v1/member/password/forgot', ['email' => 'budi@example.com'])->assertUnauthorized();
    $this->postJson('/api/v1/member/password/reset', [])->assertUnauthorized();
});

test('requesting a link sends it to an approved member, with the same response for unknown e-mails', function () {
    $pending = Member::factory()->create(['email' => 'pending@example.com']);
    $pending->forceFill(['registration_status' => Member::REG_PENDING])->save();

    $known = forgot('Budi@Example.com ')->assertOk()->json('message');
    $unknown = forgot('tidak-ada@example.com')->assertOk()->json('message');
    $notApproved = forgot('pending@example.com')->assertOk()->json('message');

    expect($unknown)->toBe($known)->and($notApproved)->toBe($known);
    Notification::assertSentToTimes($this->member, ResetMemberPassword::class, 1);
    Notification::assertNotSentTo($pending, ResetMemberPassword::class);
});

test('the link is sent from the queue, so the response does not depend on the e-mail being registered', function () {
    Queue::fake();

    forgot('budi@example.com')->assertOk();
    forgot('tidak-ada@example.com')->assertOk();

    Queue::assertPushed(SendMemberPasswordResetLink::class, 2);
    Queue::assertPushed(SendMemberPasswordResetLink::class, fn ($job) => $job->email === 'tidak-ada@example.com');
    expect(DB::table('member_password_reset_tokens')->count())->toBe(0); // token baru dibuat oleh worker
});

test('the e-mail links to the FE reset page with the token and e-mail', function () {
    forgot('budi@example.com');
    $token = sentResetToken($this->member);

    $mail = (new ResetMemberPassword($token))->toMail($this->member);

    expect($mail->actionUrl)->toBe('http://fe.test/lupa-password/atur-ulang?'.http_build_query(['token' => $token, 'email' => 'budi@example.com']));
});

test('links are throttled per e-mail', function () {
    forgot('budi@example.com')->assertOk();
    forgot('budi@example.com')->assertOk();   // < 60 detik: diterima tapi tidak mengirim e-mail kedua
    Notification::assertSentToTimes($this->member, ResetMemberPassword::class, 1);

    forgot('budi@example.com')->assertOk();
    forgot('budi@example.com')->assertStatus(429)->assertJsonValidationErrors('email');
});

test('a link can be checked before showing the form, and expires', function () {
    forgot('budi@example.com');
    $token = sentResetToken($this->member);
    $check = fn (string $t) => $this->withToken('test-token')->postJson('/api/v1/member/password/reset/check', ['email' => 'budi@example.com', 'token' => $t]);

    $check($token)->assertOk()->assertJson(['valid' => true]);
    $check('token-salah')->assertUnprocessable()->assertJsonValidationErrors('token');

    $this->travel(61)->minutes();
    $check($token)->assertUnprocessable()->assertJsonValidationErrors('token');
});

test('resetting changes the password, logs out every device, notifies the member and works only once', function () {
    $this->member->createToken('laptop', ['member']);
    $this->member->createToken('hp', ['member']);
    forgot('budi@example.com');
    $token = sentResetToken($this->member);

    resetPassword(['token' => $token])->assertOk()->assertJson(['revoked_sessions' => 2]);

    expect($this->member->fresh()->tokens()->count())->toBe(0);
    Notification::assertSentTo($this->member, MemberPasswordChanged::class);

    $this->withToken('test-token')->postJson('/api/v1/member/login', ['identifier' => 'budi@example.com', 'password' => 'BaruSekali2'])->assertOk();
    $this->withToken('test-token')->postJson('/api/v1/member/login', ['identifier' => 'budi@example.com', 'password' => 'LamaSekali1'])->assertUnprocessable();

    // Link sekali pakai
    resetPassword(['token' => $token, 'password' => 'LainLagi3', 'password_confirmation' => 'LainLagi3'])
        ->assertUnprocessable()->assertJsonValidationErrors('token');
});

test('reset validates the new password with the same rules as changing it', function () {
    forgot('budi@example.com');
    $token = sentResetToken($this->member);

    resetPassword(['token' => $token, 'password' => 'lemah', 'password_confirmation' => 'lemah'])->assertJsonValidationErrors('password');
    resetPassword(['token' => $token, 'password' => 'BaruSekali2', 'password_confirmation' => 'Beda1234'])->assertJsonValidationErrors('password');
    resetPassword(['token' => 'token-salah'])->assertJsonValidationErrors('token');

    expect(password_verify('LamaSekali1', $this->member->fresh()->password))->toBeTrue();
});

test('a registrant that is not approved cannot reset even with a valid token', function () {
    $pending = Member::factory()->create(['email' => 'pending@example.com', 'password' => 'LamaSekali1']);
    $pending->forceFill(['registration_status' => Member::REG_PENDING])->save();
    $token = Password::broker('members')->createToken($pending);

    resetPassword(['email' => 'pending@example.com', 'token' => $token])->assertJsonValidationErrors('token');
});
