<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    /**
     * Pastikan user punya salah satu role yang diizinkan.
     * Pakai di routes: ->middleware('role:admin,superadmin')
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'error' => 'unauthenticated'], 401);
        }
        if (!in_array($user->role, $roles, true)) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        return $next($request);
    }
}
