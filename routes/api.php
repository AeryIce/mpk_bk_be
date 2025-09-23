<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Auth\MagicLinkController; // <— ganti import
use Illuminate\Http\Request;

Route::get('/health', [HealthController::class, 'index']);

Route::prefix('auth')->group(function () {
    Route::get('/ping', fn () => response()->json([
        'ok' => true, 'module' => 'Auth', 'time' => now()->toIso8601String(),
    ]));
Route::post('/magic-link/request', [MagicLinkController::class, 'request'])
    ->middleware('throttle:magiclink-email');   // ganti dari throttle:5,1
Route::post('/magic-link/consume', [MagicLinkController::class, 'consume'])->middleware('throttle:10,1');
});
Route::get('/auth/magic-link/consume/{token}', function (string $token, Request $r) {
    $request = $r->merge(['token' => $token]);
    return app(MagicLinkController::class)->consume($request);
})->middleware('throttle:10,1');