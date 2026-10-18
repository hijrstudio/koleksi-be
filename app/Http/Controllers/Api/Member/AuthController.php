<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\MemberLoginRequest;
use App\Http\Resources\MemberProfileResource;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Auth member untuk frontend (Next.js) & klien lain nantinya (mis. aplikasi mobile).
 *
 * Token = Sanctum personal access token dengan masa berlaku (config membership.token_ttl_days).
 * FE menyimpannya di cookie httpOnly dari sisi server Next.js, bukan di localStorage.
 */
class AuthController extends Controller
{
    public function login(MemberLoginRequest $request): JsonResponse
    {
        $member = $request->authenticatedMember();

        $expiresAt = now()->addDays(config('membership.token_ttl_days'));
        $token = $member->createToken($request->input('device_name') ?: 'web', ['member'], $expiresAt);

        $member->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'member' => new MemberProfileResource($member),
        ]);
    }

    public function me(Request $request): MemberProfileResource
    {
        return new MemberProfileResource($request->user());
    }

    /** Cabut token yang sedang dipakai (keluar dari perangkat ini saja). */
    public function logout(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->user();
        $member->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil keluar.']);
    }
}
