<?php

namespace App\Http\Requests\Member;

use App\Models\Member;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Ganti kata sandi oleh member yang sedang login.
 *
 * - Wajib kata sandi saat ini (token yang dicuri saja tidak cukup untuk mengambil alih akun).
 * - Salah kata sandi saat ini dibatasi 5x / 15 menit per member (anti tebak).
 * - Kata sandi baru: 8–72 karakter (batas bcrypt), huruf besar + kecil + angka,
 *   tidak ada di daftar kebocoran data publik (Have I Been Pwned, k-anonymity), dan beda dari yang lama.
 */
class UpdateMemberPasswordRequest extends FormRequest
{
    public const MIN_LENGTH = 8;

    public const MAX_LENGTH = 72;

    private const MAX_WRONG_ATTEMPTS = 5;

    private const DECAY_SECONDS = 900;

    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => [
                'required', 'string', 'confirmed', 'max:'.self::MAX_LENGTH,
                Password::min(self::MIN_LENGTH)->mixedCase()->numbers()->uncompromised(),
            ],
        ];
    }

    /**
     * Cek kata sandi saat ini setelah rule dasar lolos. Dibatasi per member supaya tidak bisa ditebak
     * berulang-ulang memakai token yang sedang aktif.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('current_password')) {
                return;
            }

            $key = $this->throttleKey();
            if (RateLimiter::tooManyAttempts($key, self::MAX_WRONG_ATTEMPTS)) {
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

                throw ValidationException::withMessages([
                    'current_password' => "Terlalu banyak percobaan. Coba lagi dalam {$minutes} menit.",
                ])->status(429);
            }

            $member = $this->member();
            if (! Hash::check((string) $this->input('current_password'), $member->password)) {
                RateLimiter::hit($key, self::DECAY_SECONDS);
                $validator->errors()->add('current_password', 'Kata sandi saat ini salah.');

                return;
            }

            RateLimiter::clear($key);

            if (! $validator->errors()->has('password') && Hash::check((string) $this->input('password'), $member->password)) {
                $validator->errors()->add('password', 'Kata sandi baru harus berbeda dari kata sandi saat ini.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Kata sandi saat ini wajib diisi.',
            'password.required' => 'Kata sandi baru wajib diisi.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak sama.',
            'password.max' => 'Kata sandi maksimal '.self::MAX_LENGTH.' karakter.',
            'password.min' => 'Kata sandi minimal '.self::MIN_LENGTH.' karakter.',
            'password.mixed' => 'Kata sandi harus mengandung huruf besar dan huruf kecil.',
            'password.numbers' => 'Kata sandi harus mengandung angka.',
            'password.uncompromised' => 'Kata sandi ini pernah bocor di internet. Gunakan kata sandi lain.',
        ];
    }

    public function member(): Member
    {
        return $this->user();
    }

    private function throttleKey(): string
    {
        return 'member-password:'.$this->member()->id;
    }
}
