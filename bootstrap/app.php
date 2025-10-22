<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Middleware\HandleCors;

// (opsional) daftar console commands; aman walau class-nya belum ada
$commands = [];
if (class_exists(\App\Console\Commands\ImportMasterData::class)) {
    $commands[] = \App\Console\Commands\ImportMasterData::class;
}
if (class_exists(\App\Console\Commands\PruneExpiredPats::class)) {
    $commands[] = \App\Console\Commands\PruneExpiredPats::class;
}

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Jangan redirect tamu ke route('login') (kita pakai API/magic link)
        $middleware->redirectGuestsTo(fn () => null);

        // Alias middleware
        $middleware->alias([
            'role'        => \App\Http\Middleware\EnsureRole::class,
            'pat.expires' => \App\Http\Middleware\EnsurePatIsNotExpired::class,
            'env.basic'   => \App\Http\Middleware\EnvBasicAuth::class,
        ]);

        // Global middlewares
        $middleware->append(HandleCors::class);                           // CORS (config/cors.php)
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class); // header keamanan dasar
        $middleware->append(\App\Http\Middleware\RequestLogger::class);   // log tiap request (toggle via ENV)
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Request API tanpa bearer -> 401 JSON (no redirect)
        $exceptions->renderable(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['ok' => false, 'error' => 'unauthenticated'], 401);
            }
        });
    })
    ->withCommands($commands)
    ->create();

/**
 * Force zona waktu dari ENV agar timestamp log pakai WIB.
 * Aman meski config ke-cache, karena dieksekusi setelah app dibuat.
 */
date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Jakarta'));
config([
    'app.timezone'                     => env('APP_TIMEZONE', 'Asia/Jakarta'),
    'logging.channels.daily.timezone'  => env('APP_TIMEZONE', 'Asia/Jakarta'),
    'logging.channels.single.timezone' => env('APP_TIMEZONE', 'Asia/Jakarta'),
]);

return $app;
