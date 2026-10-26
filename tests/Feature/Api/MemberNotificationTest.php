<?php

use App\Models\Member;
use App\Models\User;
use App\Notifications\MemberPasswordChanged;
use App\Notifications\MemberRegistrationApproved;
use App\Notifications\MembershipExpired;
use App\Notifications\MembershipRenewalReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** Notifikasi tersimpan seperti hasil channel `database` Laravel. */
function storeNotification(Member $member, string $class, array $data, ?Carbon $readAt = null, ?Carbon $at = null): DatabaseNotification
{
    $notification = $member->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => $class,
        'data' => $data,
        'read_at' => $readAt,
    ]);

    if ($at) {
        $notification->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    return $notification;
}

beforeEach(function () {
    $this->member = Member::factory()->create();
});

test('notification endpoints require a member token', function () {
    $this->getJson('/api/v1/member/notifications')->assertUnauthorized();
    $this->postJson('/api/v1/member/notifications/read')->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/member/notifications')->assertUnauthorized();
});

test('lists own notifications newest first, without billing reminders, with whitelisted params', function () {
    storeNotification($this->member, MemberRegistrationApproved::class, [
        'type' => 'membership.registration_approved',
        'title' => 'Pendaftaran disetujui',
        'message' => 'Selamat!',
        'amount' => 300000,
        'action_url' => 'http://fe.test/masuk',
    ], at: now()->subDays(3));
    storeNotification($this->member, MemberPasswordChanged::class, [
        'type' => 'account.password_changed',
        'title' => 'Kata sandi diubah',
        'message' => 'Kata sandi akun Anda diubah.',
        'changed_at' => '2026-10-20T10:00:00+07:00',
        'ip' => '203.0.113.7',
    ], readAt: now(), at: now()->subDay());
    // Sudah diwakili `billing` di /member/me → tidak ditampilkan
    storeNotification($this->member, MembershipRenewalReminder::class, ['type' => 'membership.renewal_reminder', 'days_left' => 30]);
    storeNotification($this->member, MembershipExpired::class, ['type' => 'membership.expired']);
    // Milik member lain
    storeNotification(Member::factory()->create(), MemberPasswordChanged::class, ['type' => 'account.password_changed']);

    Sanctum::actingAs($this->member, ['member']);

    $response = $this->getJson('/api/v1/member/notifications')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.unread_count', 1)
        ->assertJsonPath('data.0.type', 'account.password_changed')
        ->assertJsonPath('data.0.read', true)
        ->assertJsonPath('data.0.params.changed_at', '2026-10-20T10:00:00+07:00')
        ->assertJsonPath('data.1.type', 'membership.registration_approved')
        ->assertJsonPath('data.1.read', false)
        ->assertJsonPath('data.1.params.amount', 300000)
        ->assertJsonPath('data.1.title', 'Pendaftaran disetujui');

    expect($response->json('data.0.params'))->not->toHaveKey('ip')
        ->and($response->json('data.1.params'))->not->toHaveKey('action_url');
});

test('marks one notification or all of them as read', function () {
    $first = storeNotification($this->member, MemberPasswordChanged::class, ['type' => 'account.password_changed']);
    $second = storeNotification($this->member, MemberRegistrationApproved::class, ['type' => 'membership.registration_approved']);
    $hidden = storeNotification($this->member, MembershipRenewalReminder::class, ['type' => 'membership.renewal_reminder']);
    $other = storeNotification(Member::factory()->create(), MemberPasswordChanged::class, ['type' => 'account.password_changed']);

    Sanctum::actingAs($this->member, ['member']);

    $this->postJson('/api/v1/member/notifications/read', ['id' => $first->id])
        ->assertOk()
        ->assertJsonPath('meta.unread_count', 1);
    expect($first->fresh()->read_at)->not->toBeNull()
        ->and($second->fresh()->read_at)->toBeNull();

    // Notifikasi member lain tidak bisa disentuh
    $this->postJson('/api/v1/member/notifications/read', ['id' => $other->id])->assertOk();
    expect($other->fresh()->read_at)->toBeNull();

    $this->postJson('/api/v1/member/notifications/read')
        ->assertOk()
        ->assertJsonPath('meta.unread_count', 0);
    expect($second->fresh()->read_at)->not->toBeNull()
        ->and($hidden->fresh()->read_at)->toBeNull();

    $this->postJson('/api/v1/member/notifications/read', ['id' => 'bukan-uuid'])->assertUnprocessable();
});
