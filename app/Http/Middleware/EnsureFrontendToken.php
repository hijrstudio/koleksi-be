<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proteksi API publik yang dikonsumsi frontend (Next.js) dengan satu static bearer token.
 * Token disimpan di .env (FRONTEND_API_TOKEN), generate dengan `php artisan api:frontend-token`.
 */
class EnsureFrontendToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.frontend.api_token');
        $given = (string) $request->bearerToken();

        // Token belum dikonfigurasi = tolak semua, jangan sampai API terbuka tanpa sengaja.
        // hash_equals: perbandingan constant-time, aman dari timing attack.
        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
