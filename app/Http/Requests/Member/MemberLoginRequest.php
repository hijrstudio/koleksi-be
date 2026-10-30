<?php

namespace App\Http\Requests\Member;

use App\Models\Member;
use App\Support\PhoneNumber;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Login member dengan e-mail ATAU nomor HP + password.
 *
 * Rate limit 2 lapis supaya tetap tahan brute force walau IP dirotasi:
 *   - identifier + IP : 5 percobaan gagal / menit
 *   - identifier saja : 20 percobaan gagal / jam
 */
class MemberLoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS_PER_IP = 5;

    private const MAX_ATTEMPTS_PER_ACCOUNT = 20;

    private const FAILED_MESSAGE = 'E-mail/No. HP atau password salah.';

    /** Hash bcrypt (cost 12) untuk string acak — hanya untuk menyamakan waktu respons saat akun tidak ada. */
    private const DUMMY_HASH = '$2y$12$2jLe8.NW57rxSm1MXXgqA.5txMWxbnN.5x4cYg4orpBZ8j6jxsa3m';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'identifier.required' => 'E-mail atau No. HP wajib diisi.',
            'identifier.max' => 'E-mail atau No. HP terlalu panjang.',
            'password.required' => 'Password wajib diisi.',
            'password.max' => 'Password terlalu panjang.',
        ];
    }

    /**
     * Validasi kredensial. Pesan gagal sengaja sama untuk "akun tidak ada" & "password salah"
     * supaya tidak bisa dipakai menebak e-mail/No. HP yang terdaftar.
     *
     * @throws ValidationException
     */
    public function authenticatedMember(): Member
    {
        $this->ensureIsNotRateLimited();

        $member = $this->findMember();

        // Hash::check tetap dijalankan walau member tidak ada → waktu respons setara (anti timing attack)
        $valid = Hash::check($this->string('password')->toString(), $member?->password ?? self::DUMMY_HASH);

        if (! $member || ! $valid) {
            RateLimiter::hit($this->ipThrottleKey(), 60);
            RateLimiter::hit($this->accountThrottleKey(), 3600);

            throw ValidationException::withMessages(['identifier' => self::FAILED_MESSAGE]);
        }

        RateLimiter::clear($this->ipThrottleKey());
        RateLimiter::clear($this->accountThrottleKey());

        // Pendaftar yang belum membayar iuran pertama belum boleh masuk. Pesan spesifik hanya setelah kata sandi
        // benar, jadi tidak membocorkan status pendaftaran ke orang lain.
        if (! $member->isRegistrationApproved()) {
            throw ValidationException::withMessages(['identifier' => match ($member->registration_status) {
                Member::REG_UNVERIFIED => 'E-mail Anda belum diverifikasi. Cek kotak masuk e-mail untuk link verifikasi.',
                Member::REG_AWAITING_PAYMENT => 'Selesaikan pembayaran iuran pendaftaran untuk mengaktifkan akun. Link pembayaran ada di e-mail Anda (bisa dikirim ulang dari halaman daftar).',
                Member::REG_PENDING => 'Pendaftaran Anda sedang diproses pengurus. Kami akan mengabari lewat e-mail.',
                default => 'Pendaftaran Anda belum dapat disetujui. Silakan hubungi pengurus KOLEKSI.',
            }]);
        }

        return $member;
    }

    private function findMember(): ?Member
    {
        $identifier = $this->identifier();

        if ($identifier === null) {
            return null;
        }

        return Member::query()
            ->where(str_contains($identifier, '@') ? 'email' : 'no_hp', $identifier)
            ->first();
    }

    /** E-mail (lowercase) atau No. HP yang dinormalisasi ke 08xxx; null kalau formatnya tidak valid. */
    private function identifier(): ?string
    {
        $raw = Str::lower(trim($this->string('identifier')->toString()));

        if (str_contains($raw, '@')) {
            return filter_var($raw, FILTER_VALIDATE_EMAIL) ? $raw : null;
        }

        return PhoneNumber::normalize($raw) ?: null;
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        $key = match (true) {
            RateLimiter::tooManyAttempts($this->ipThrottleKey(), self::MAX_ATTEMPTS_PER_IP) => $this->ipThrottleKey(),
            RateLimiter::tooManyAttempts($this->accountThrottleKey(), self::MAX_ATTEMPTS_PER_ACCOUNT) => $this->accountThrottleKey(),
            default => null,
        };

        if ($key === null) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($key);
        $wait = $seconds < 60 ? "{$seconds} detik" : ceil($seconds / 60).' menit';

        throw ValidationException::withMessages([
            'identifier' => "Terlalu banyak percobaan masuk. Coba lagi dalam {$wait}.",
        ])->status(429);
    }

    private function accountThrottleKey(): string
    {
        return 'member-login:'.sha1($this->identifier() ?? Str::lower($this->string('identifier')->toString()));
    }

    private function ipThrottleKey(): string
    {
        return $this->accountThrottleKey().'|'.$this->ip();
    }
}
