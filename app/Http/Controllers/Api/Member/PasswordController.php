<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateMemberPasswordRequest;
use App\Notifications\MemberPasswordChanged;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PasswordController extends Controller
{
    /**
     * Ganti kata sandi. Semua sesi login lain (perangkat lain) dicabut; sesi yang sedang dipakai tetap aktif.
     * Member diberi tahu lewat notifikasi + e-mail, supaya sadar kalau bukan dia yang mengganti.
     */
    public function update(UpdateMemberPasswordRequest $request): JsonResponse
    {
        $member = $request->member();
        $currentTokenId = $member->currentAccessToken()->getKey();

        $revoked = DB::transaction(function () use ($member, $request, $currentTokenId) {
            $member->forceFill([
                'password' => $request->validated('password'),   // di-hash otomatis (cast 'hashed')
                'must_change_password' => false,
                'remember_token' => null,
            ])->save();

            return $member->tokens()->whereKeyNot($currentTokenId)->delete();
        });

        $member->notify(new MemberPasswordChanged(now(), $request->ip()));

        return response()->json([
            'message' => 'Kata sandi berhasil diubah.',
            'revoked_sessions' => $revoked,
        ]);
    }
}
