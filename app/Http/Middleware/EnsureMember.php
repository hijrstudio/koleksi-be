<?php

namespace App\Http\Middleware;

use App\Models\Member;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pastikan token Sanctum yang dipakai milik member (bukan token admin CMS / User).
 * Dipasang setelah `auth:sanctum`.
 */
class EnsureMember
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof Member) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
