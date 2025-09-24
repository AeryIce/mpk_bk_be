<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsurePatIsNotExpired
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()?->currentAccessToken();

        // Kalau ternyata tidak ada token aktif, biarkan auth:sanctum yang handle (401)
        if (!$token) {
            return $next($request);
        }

        // Jika token punya expiry dan sudah lewat → hapus & balas 401 khusus
        if ($token->expires_at && now()->greaterThan($token->expires_at)) {
            // opsional: hapus token supaya benar-benar tak bisa dipakai lagi
            try { $token->delete(); } catch (\Throwable $e) {}
            return response()->json(['ok' => false, 'error' => 'token_expired'], 401);
        }

        return $next($request);
    }
}
