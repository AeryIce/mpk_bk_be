<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Auth\MagicLinkController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::get('/health', [HealthController::class, 'index'])->name('health');

/**
 * AUTH MODULE (magic-link)
 */
Route::prefix('auth')->group(function () {
    Route::get('/ping', fn () => response()->json([
        'ok' => true,
        'module' => 'Auth',
        'time' => now()->toIso8601String(),
    ]))->name('auth.ping');

    // Request magic link (rate limit pakai key khusus dari AppServiceProvider)
  // GANTI limiter: magiclink-email
    Route::post('/magic-link/request', [MagicLinkController::class, 'request'])
        ->middleware('throttle:magiclink-email')
        ->name('auth.magiclink.request');

    // GANTI limiter: magiclink-consume
    Route::post('/magic-link/consume', [MagicLinkController::class, 'consume'])
        ->middleware('throttle:magiclink-consume')
        ->name('auth.magiclink.consume');

    Route::get('/magic-link/consume/{token}', function (string $token, Request $r) {
        $request = $r->merge(['token' => $token]);
        return app(MagicLinkController::class)->consume($request);
    })
        ->middleware('throttle:magiclink-consume')
        ->name('auth.magiclink.consume.get');
});

/**
 * PROTECTED ROUTES (Sanctum Bearer)
 */
Route::middleware('auth:sanctum')->get('/me', function (\Illuminate\Http\Request $request) {
    $u = $request->user();
    if (!$u) {
        // fallback defensif: kalau middleware lolos tapi user null, balikin 401
        return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
    }

    return response()->json([
        'ok' => true,
        'user' => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
        ],
    ]);
})->name('auth.me');

Route::middleware('auth:sanctum')->post('/logout', function (\Illuminate\Http\Request $request) {
    $token = $request->user()?->currentAccessToken();
    if ($token) {
        $token->delete();
    }
    return response()->json(['ok' => true]);
})->name('auth.logout');