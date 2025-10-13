<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsurePatIsNotExpired
{
    /**
     * Cek masa berlaku Personal Access Token (Sanctum).
     * Jika token punya kolom expires_at dan sudah lewat → 401.
     * Jika tidak ada expires_at, lepasin (dev/demo).
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token && $token->expires_at && now()->greaterThan($token->expires_at)) {
            return response()->json(['ok' => false, 'error' => 'token_expired'], 401);
        }

        return $next($request);
    }
}
