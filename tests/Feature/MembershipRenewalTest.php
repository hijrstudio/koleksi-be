<?php

use App\Models\Member;
use App\Models\MemberRenewalReminder;
use App\Models\User;
use App\Notifications\MembershipExpired;
use App\Notifications\MembershipRenewalReminder;
use App\Services\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    $this->service = app(MembershipService::class);

    // Bergabung 10 Feb 2025, periode 2026-2027 lunas → paid_until 9 Feb 2027, jatuh tempo 10 Feb 2027
    $this->member = Member::factory()->create(['tgl_bergabung' => '2025-02-10']);
    $this->travelTo(Carbon::parse('2026-10-08'));
    $this->service->waive($this->member, $this->service->termForYear($this->member, 2026));
});

function runRemindersOn(string $date): void
{
    test()->travelTo(Carbon::parse($date.' 08:00'));
    test()->artisan('members:send-renewal-reminders')->assertSuccessful();
}

test('no reminder when renewal is still far away', function () {
    runRemindersOn('2026-12-01');

    Notification::assertNothingSent();
});

test('reminders are sent at H-30, H-7 and on the due date, each only once', function () {
    runRemindersOn('2027-01-11');   // H-30
    runRemindersOn('2027-01-12');   // masih tahap 30 → tidak dikirim lagi
    runRemindersOn('2027-02-03');   // H-7
    runRemindersOn('2027-02-10');   // hari-H
    runRemindersOn('2027-02-10');   // dijalankan dua kali → tetap sekali

    Notification::assertSentToTimes($this->member, MembershipRenewalReminder::class, 3);

    expect(MemberRenewalReminder::where('member_id', $this->member->id)->pluck('stage')->all())
        ->toEqualCanonicalizing(['30', '7', '0']);

    Notification::assertSentTo($this->member, MembershipRenewalReminder::class, function ($n) {
        return $n->renewalTerm->label() === '2027-2028'
            && $n->renewalTerm->start->toDateString() === '2027-02-10';
    });
});

test('when scheduler missed days only the most urgent stage is sent', function () {
    runRemindersOn('2027-02-05');   // H-5: tahap 30 & 7 terlewat → kirim tahap 7 saja

    Notification::assertSentToTimes($this->member, MembershipRenewalReminder::class, 1);
    expect(MemberRenewalReminder::where('member_id', $this->member->id)->value('stage'))->toBe('7');
});

test('reminder uses mail channel only for members with email', function () {
    runRemindersOn('2027-01-11');

    Notification::assertSentTo($this->member, MembershipRenewalReminder::class,
        fn ($n, array $channels) => $channels === ['database', 'mail']);

    $noEmail = Member::factory()->create(['tgl_bergabung' => '2025-02-10', 'email' => null]);
    $this->service->waive($noEmail, $this->service->termForYear($noEmail, 2026));
    runRemindersOn('2027-01-12');

    Notification::assertSentTo($noEmail, MembershipRenewalReminder::class,
        fn ($n, array $channels) => $channels === ['database']);
});

test('manual status members are not reminded', function () {
    $admin = User::factory()->create();
    $this->service->setManualStatus($this->member, 'active', $admin, 'Pengurus');

    runRemindersOn('2027-01-11');

    Notification::assertNothingSent();
});

test('paying the next term restarts the reminder cycle', function () {
    runRemindersOn('2027-01-11');
    $this->service->markPaid($this->service->invoiceFor($this->member, $this->service->termForYear($this->member, 2027)), 'transfer');
    $this->service->refreshStatus($this->member);

    runRemindersOn('2027-02-03');   // sudah lunas 2027-2028 → tidak ada pengingat

    Notification::assertSentToTimes($this->member, MembershipRenewalReminder::class, 1);
    expect($this->member->fresh()->renewalDate()->toDateString())->toBe('2028-02-10');
});

test('member becomes inactive after grace period and gets notified once', function () {
    $this->travelTo(Carbon::parse('2027-03-11'));   // hari terakhir masa tenggang
    $this->artisan('members:refresh-statuses')->assertSuccessful();
    expect($this->member->fresh()->status)->toBe('active');

    $this->travelTo(Carbon::parse('2027-03-12'));
    $this->artisan('members:refresh-statuses')->assertSuccessful();
    $this->artisan('members:refresh-statuses')->assertSuccessful();

    expect($this->member->fresh()->status)->toBe('inactive');
    Notification::assertSentToTimes($this->member, MembershipExpired::class, 1);
    expect($this->member->statusLogs()->latest('id')->first()->reason)->toContain('masa tenggang berakhir');
});

test('dry run sends nothing', function () {
    $this->travelTo(Carbon::parse('2027-01-11'));
    $this->artisan('members:send-renewal-reminders', ['--dry-run' => true])->assertSuccessful();

    Notification::assertNothingSent();
    expect(MemberRenewalReminder::count())->toBe(0);
});

test('notification payload for the member app and email content', function () {
    $notification = new MembershipRenewalReminder($this->service->renewalTerm($this->member), 30);

    $data = $notification->toArray($this->member);
    expect($data['type'])->toBe('membership.renewal_reminder')
        ->and($data['period_label'])->toBe('2027-2028')
        ->and($data['renewal_date'])->toBe('2027-02-10')
        ->and($data['days_left'])->toBe(30)
        ->and($data['title'])->toBe('Daftar ulang keanggotaan dalam 30 hari')
        ->and($data['action_url'])->toBe(config('membership.renewal_url'));

    $mail = $notification->toMail($this->member);
    expect($mail->subject)->toBe('Daftar ulang keanggotaan dalam 30 hari')
        ->and($mail->actionUrl)->toBe(config('membership.renewal_url'));
});
