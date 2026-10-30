<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\RegisterMemberRequest;
use App\Models\Member;
use App\Services\MemberRegistrationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pendaftaran member baru dari website.
 *   POST /api/v1/registrations                     (FE, FRONTEND_API_TOKEN) simpan pendaftaran
 *   POST /api/v1/registrations/resend-verification (FE, FRONTEND_API_TOKEN) kirim ulang link verifikasi / link bayar
 *   GET  /api/v1/registrations/verify/{member}/{hash}?expires=&signature=   link di e-mail (dibuka browser)
 *        → berhasil: langsung ke halaman bayar iuran pendaftaran (FE /daftar/bayar?token=...)
 */
class MemberRegistrationController extends Controller
{
    public function __construct(private MemberRegistrationService $registrations) {}

    public function store(RegisterMemberRequest $request): JsonResponse
    {
        try {
            $member = $this->registrations->register(
                $request->validated(),
                $request->boolean('has_vehicle') ? $request->file('documents', []) : [],
                $request->ip(),
            );
        } catch (UniqueConstraintViolationException) {
            // Dua pendaftaran dengan data yang sama dikirim bersamaan → yang kalah dapat pesan biasa, bukan 500
            throw ValidationException::withMessages(['email' => 'E-mail, No. HP, atau kendaraan ini sudah terdaftar.']);
        }

        return response()->json([
            'message' => 'Pendaftaran berhasil. Silakan cek e-mail untuk verifikasi.',
            'data' => ['email' => $member->email],
        ], 201);
    }

    /**
     * Kirim ulang link verifikasi (belum verifikasi) atau link bayar (belum bayar).
     * Selalu 200 dengan pesan yang sama — tidak membocorkan apakah e-mail terdaftar.
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $email = Str::lower(trim((string) $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ])['email']));

        // Per e-mail: 1x / menit (anti spam ke kotak masuk orang), di samping throttle per IP di route
        $key = 'registration-resend:'.sha1($email);
        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw ValidationException::withMessages([
                'email' => 'Tunggu '.RateLimiter::availableIn($key).' detik sebelum mengirim ulang.',
            ])->status(429);
        }
        RateLimiter::hit($key, 60);

        $this->registrations->resend($email);

        return response()->json([
            'message' => 'Kalau e-mail tersebut terdaftar dan pendaftarannya belum selesai, link baru sudah dikirim.',
        ]);
    }

    /**
     * Link verifikasi di e-mail. Berhasil (atau diklik ulang sebelum bayar) → halaman bayar iuran pendaftaran di FE.
     * Selain itu → {verified_redirect}?status=expired|invalid|active (active = sudah anggota, silakan masuk).
     */
    public function verify(Request $request, string $member, string $hash): RedirectResponse
    {
        $redirect = fn (string $status) => redirect()->away(
            config('membership.registration.verified_redirect').'?'.http_build_query(['status' => $status])
        );

        if (! URL::hasCorrectSignature($request)) {
            return $redirect('invalid');
        }
        if (! URL::signatureHasNotExpired($request)) {
            return $redirect('expired');
        }

        $found = ctype_digit($member) ? Member::query()->find((int) $member) : null;
        $status = $found ? $this->registrations->verifyEmail($found, $hash) : 'invalid';

        return in_array($status, ['verified', 'already'], true)
            ? redirect()->away($this->registrations->paymentUrl($found->refresh()))
            : $redirect($status);
    }
}
