<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\ResetMemberPasswordRequest;
use App\Jobs\SendMemberPasswordResetLink;
use App\Models\Member;
use App\Notifications\MemberPasswordChanged;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lupa kata sandi member (FE: /lupa-password → e-mail → /lupa-password/atur-ulang).
 *
 * Memakai password broker `members` (config/auth.php): token acak disimpan dalam bentuk hash, sekali pakai,
 * kedaluwarsa, dan jeda minimal antar permintaan per e-mail. Pengiriman link diproses di queue
 * (SendMemberPasswordResetLink) dan pengecekan link dibungkus Timebox, supaya waktu respons tidak
 * membedakan e-mail terdaftar dan tidak.
 *
 * Hanya member yang pendaftarannya sudah disetujui (yang memang bisa login) yang bisa mengatur ulang;
 * selain itu diperlakukan seperti e-mail tidak terdaftar.
 */
class PasswordResetController extends Controller
{
    /** Permintaan link per e-mail per jam — menahan "e-mail bombing" ke kotak masuk orang lain. */
    private const MAX_LINKS_PER_HOUR = 3;

    private const INVALID_LINK = 'Link atur ulang kata sandi tidak valid atau sudah kedaluwarsa. Silakan minta link baru.';

    /** Selalu 200 dengan pesan yang sama — tidak membocorkan apakah e-mail terdaftar. */
    public function forgot(Request $request): JsonResponse
    {
        $email = $this->validatedEmail($request);

        $key = 'member-password-forgot:'.sha1($email);
        if (RateLimiter::tooManyAttempts($key, self::MAX_LINKS_PER_HOUR)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            throw ValidationException::withMessages([
                'email' => "Terlalu banyak permintaan untuk e-mail ini. Coba lagi dalam {$minutes} menit.",
            ])->status(429);
        }
        RateLimiter::hit($key, 3600);

        // Diproses di queue: waktu respons tidak boleh berbeda antara e-mail terdaftar & tidak terdaftar.
        SendMemberPasswordResetLink::dispatch($email);

        return response()->json([
            'message' => 'Kalau e-mail tersebut terdaftar sebagai anggota, link untuk mengatur ulang kata sandi sudah dikirim.',
        ]);
    }

    /** Cek link sebelum form ditampilkan, supaya member tidak mengetik kata sandi untuk link yang sudah mati. */
    public function check(Request $request): JsonResponse
    {
        $email = $this->validatedEmail($request);
        $token = (string) $request->validate(['token' => ['required', 'string', 'max:255']])['token'];

        $broker = $this->broker();
        $valid = $broker->getTimebox()->call(function () use ($broker, $email, $token) {
            $member = $broker->getUser($this->credentials($email));

            return $member !== null && $broker->tokenExists($member, $token);
        }, 200_000);

        if (! $valid) {
            throw ValidationException::withMessages(['token' => self::INVALID_LINK]);
        }

        return response()->json(['valid' => true]);
    }

    /**
     * Simpan kata sandi baru. Semua sesi login (semua perangkat) dicabut — siapa pun yang mungkin memegang
     * akun ini harus login ulang — dan member diberi tahu lewat notifikasi + e-mail.
     */
    public function reset(ResetMemberPasswordRequest $request): JsonResponse
    {
        $email = $request->email();
        $revoked = 0;

        $status = $this->broker()->reset(
            [...$this->credentials($email), ...$request->only('token', 'password', 'password_confirmation')],
            function (Member $member, string $password) use (&$revoked) {
                $revoked = DB::transaction(function () use ($member, $password) {
                    $member->forceFill([
                        'password' => $password,   // di-hash otomatis (cast 'hashed')
                        'must_change_password' => false,
                        'remember_token' => null,
                    ])->save();

                    return $member->tokens()->delete();
                });

                event(new PasswordReset($member));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => self::INVALID_LINK]);
        }

        // Pemilik akun yang sudah membuktikan akses ke e-mailnya tidak perlu menunggu kunci login (MemberLoginRequest)
        RateLimiter::clear('member-login:'.sha1($email));

        $member = Member::query()->where('email', $email)->first();
        $member?->notify(new MemberPasswordChanged(now(), $request->ip()));

        return response()->json([
            'message' => 'Kata sandi berhasil diatur ulang. Silakan masuk dengan kata sandi baru.',
            'revoked_sessions' => $revoked,
        ]);
    }

    private function broker(): PasswordBroker
    {
        return Password::broker('members');
    }

    /**
     * @return array<string, string>
     */
    private function credentials(string $email): array
    {
        return SendMemberPasswordResetLink::credentials($email);
    }

    private function validatedEmail(Request $request): string
    {
        return Str::lower(trim((string) $request->validate(
            ['email' => ['required', 'string', 'email', 'max:255']],
            ['email.required' => 'Alamat e-mail wajib diisi.', 'email.email' => 'Masukkan alamat e-mail yang valid.'],
        )['email']));
    }
}
